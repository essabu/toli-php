<?php

declare(strict_types=1);

namespace Essabu\Toli\Tests;

use Essabu\Toli\Exception\ToliAuthError;
use Essabu\Toli\Exception\ToliConfigError;
use Essabu\Toli\Exception\ToliProtocolError;
use Essabu\Toli\Exception\ToliUnavailableError;
use Essabu\Toli\Question\Choice;
use Essabu\Toli\Question\Noul;
use Essabu\Toli\Question\Score;
use Essabu\Toli\Reading;
use Essabu\Toli\Route;
use Essabu\Toli\Thresholds;
use Essabu\Toli\Toli;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shared conformance suite, replayed against the PHP SDK.
 *
 * These cases live in spec/conformance/ and are replayed by every SDK. They are
 * the only thing that keeps "confirm" meaning the same in PHP and in Rust — a
 * language-specific test proves a language-specific belief.
 */
final class ConformanceTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, string}> */
    public static function routingCases(): array
    {
        $spec = self::spec('routing.json');
        $out = [];

        foreach ($spec['cases'] as $case) {
            $out[$case['name']] = [$case['answer'], $case['expect']];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    #[DataProvider('routingCases')]
    public function test_routing(array $answer, string $expected): void
    {
        $spec = self::spec('routing.json');
        $thresholds = Thresholds::measured(
            $spec['thresholds']['act'],
            $spec['thresholds']['confirm'],
            'conformance suite',
            '2026-09-19',
        );

        // The fixture describes the answer as it arrives on the wire, minus the
        // `kind` discriminator, which the parser infers from the payload shape.
        $wire = $answer;
        unset($wire['kind']);

        $reading = Reading::parse(
            ['model' => 'toli-1', 'answers' => ['q' => $wire]],
            'toli-1',
            $thresholds,
        );

        $this->assertSame($expected, $reading->route('q')->value);
    }

    public function test_a_noul_at_perfect_doubt_never_acts(): void
    {
        // Singled out from the table because it is THE mistake the contract
        // guards against: comparing `noul` straight to the act threshold would
        // make 0.5 — the admission of ignorance — look like near-certainty.
        $reading = $this->read(['answers' => ['q' => ['noul' => 0.5]]]);

        $this->assertSame('escalate', $reading->route('q')->value);
        $this->assertSame(0.0, $reading->answer('q')->certainty);
    }

    public function test_a_confident_no_is_worth_a_confident_yes(): void
    {
        $no = $this->read(['answers' => ['q' => ['noul' => 0.02]]]);
        $yes = $this->read(['answers' => ['q' => ['noul' => 0.98]]]);

        $this->assertSame('act', $no->route('q')->value);
        $this->assertSame('act', $yes->route('q')->value);
        $this->assertEqualsWithDelta($yes->answer('q')->certainty, $no->answer('q')->certainty, 1e-9);
        $this->assertFalse($no->answer('q')->isYes());
        $this->assertTrue($yes->answer('q')->isYes());
    }

    public function test_every_question_travels_in_a_single_request(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok(['model' => 'toli-1', 'answers' => ['a' => ['noul' => 0.9]]])]);

        $this->toli($http)->ask('a state', [
            'a' => new Noul('Something is true'),
            'b' => new Choice('Which team', ['x' => 'X', 'other' => 'None of the above']),
            'c' => new Score('How tense', ['Calm', 'Tense']),
        ]);

        $this->assertCount(1, $http->requests, 'Three questions must travel in ONE request.');

        $body = json_decode((string) $http->requests[0]->getBody(), true);
        $this->assertSame(['a', 'b', 'c'], array_keys($body['questions']));
        $this->assertSame('choice', $body['questions']['b']['kind']);
        $this->assertSame(['x' => 'X', 'other' => 'None of the above'], $body['questions']['b']['criteria']);
    }

    public function test_a_structured_state_is_serialised_as_is(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok(['model' => 'toli-1', 'answers' => ['a' => ['noul' => 0.9]]])]);
        $state = ['interface' => 'CommCare', 'feature' => 'Sync'];

        $this->toli($http)->ask($state, ['a' => new Noul('Blocked today')]);

        $body = json_decode((string) $http->requests[0]->getBody(), true);
        $this->assertSame($state, $body['state'], 'A structured state must not be flattened into text.');
    }

    public function test_the_served_model_wins_over_the_requested_one(): void
    {
        // Pinning a version only protects you if you can tell you were served
        // something else.
        $reading = $this->read(['model' => 'toli-1.14', 'answers' => ['q' => ['noul' => 0.1]]], requested: 'toli-1');

        $this->assertSame('toli-1.14', $reading->model);
    }

    public function test_missing_usage_counts_as_zero(): void
    {
        $reading = $this->read(['model' => 'toli-1', 'answers' => ['q' => ['noul' => 0.9]]]);

        $this->assertSame(['input_tokens' => 0, 'output_tokens' => 0], $reading->usage);
    }

    public function test_a_rejected_key_is_not_retried(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::status(401, ['error' => 'invalid api key'])]);

        try {
            $this->toli($http)->ask('s', ['q' => new Noul('x')]);
            $this->fail('A rejected key must raise ToliAuthError.');
        } catch (ToliAuthError $e) {
            $this->assertSame(401, $e->status);
            $this->assertCount(1, $http->requests, 'Retrying a rejected key would change nothing.');
        }
    }

    public function test_a_dropped_connection_is_retried(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::networkFailure(),
            FakeHttpClient::ok(['model' => 'toli-1', 'answers' => ['q' => ['noul' => 0.9]]]),
        ]);

        $reading = $this->toli($http)->ask('s', ['q' => new Noul('x')]);

        $this->assertCount(2, $http->requests, 'A dropped connection is transient: 3 readings out of 897 were lost without this.');
        $this->assertSame('toli-1', $reading->model);
    }

    public function test_an_overloaded_server_is_retried_then_given_up_on(): void
    {
        $http = new FakeHttpClient(array_fill(0, 6, FakeHttpClient::status(503, ['error' => 'unavailable'])));

        $this->expectException(ToliUnavailableError::class);

        try {
            $this->toli($http)->ask('s', ['q' => new Noul('x')]);
        } finally {
            $this->assertCount(5, $http->requests, 'Five attempts, then hand control back.');
        }
    }

    public function test_the_server_retry_after_wins_over_our_backoff(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::status(429, ['error' => 'rate limited'], ['retry-after' => '2']),
            FakeHttpClient::ok(['model' => 'toli-1', 'answers' => ['q' => ['noul' => 0.9]]]),
        ]);
        $waits = [];

        $this->toli($http, function (int $s) use (&$waits): void {
            $waits[] = $s;
        })
            ->ask('s', ['q' => new Noul('x')]);

        $this->assertSame([2], $waits, 'A server that states its own retry-after knows its load better than we do.');
    }

    public function test_a_200_without_answers_is_a_protocol_error(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok(['model' => 'toli-1'])]);

        $this->expectException(ToliProtocolError::class);

        try {
            $this->toli($http)->ask('s', ['q' => new Noul('x')]);
        } finally {
            $this->assertCount(1, $http->requests, 'A well-formed 200 is not retried.');
        }
    }

    public function test_an_error_carries_the_request_id_and_a_bounded_excerpt(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::raw(400, str_repeat('x', 5000), ['x-request-id' => 'req_8fa31']),
        ]);

        try {
            $this->toli($http)->ask('s', ['q' => new Noul('x')]);
            $this->fail('Expected a protocol error.');
        } catch (ToliProtocolError $e) {
            $this->assertSame('req_8fa31', $e->requestId);
            $this->assertSame(300, mb_strlen((string) $e->bodyExcerpt), 'Enough to diagnose, too little to spill a state into a log.');
        }
    }

    public function test_a_single_option_choice_is_refused(): void
    {
        $this->expectException(ToliConfigError::class);

        new Choice('Which team', ['application' => 'The app']);
    }

    public function test_inverted_thresholds_are_refused(): void
    {
        $this->expectException(ToliConfigError::class);

        Thresholds::measured(0.6, 0.85, 'x', '2026-09-19');
    }

    public function test_out_of_bounds_thresholds_are_refused(): void
    {
        $this->expectException(ToliConfigError::class);

        Thresholds::measured(1.4, 0.6, 'x', '2026-09-19');
    }

    public function test_measured_thresholds_must_say_on_what_and_when(): void
    {
        $this->expectException(ToliConfigError::class);

        Thresholds::measured(0.9, 0.6, '', '');
    }

    public function test_advisory_thresholds_can_never_reach_act(): void
    {
        // The guarantee clinical use rests on: for screening or orientation —
        // sickle-cell disease, haemophilia, rare diseases — a wrong answer is
        // not re-routed the next morning. No confidence buys an automatic
        // action, so the route tops out at CONFIRM by construction.
        $advisory = Thresholds::advisory(0.60, 'screening corpus', '2026-09-19');

        foreach ([1.0, 0.99, 0.85, 0.61] as $certainty) {
            $this->assertSame(Route::Confirm, $advisory->route($certainty), "certainty {$certainty} must never act");
        }

        $this->assertSame(Route::Escalate, $advisory->route(0.59));
        $this->assertTrue($advisory->toArray()['advisory'], 'A log must show the reading was advisory-only.');
    }

    public function test_an_advisory_reading_never_returns_act_end_to_end(): void
    {
        $reading = Reading::parse(
            ['model' => 'toli-1', 'answers' => ['q' => ['choice' => 'screen', 'confidence' => 0.999]]],
            'toli-1',
            Thresholds::advisory(0.60, 'screening corpus', '2026-09-19'),
        );

        $this->assertSame('confirm', $reading->route('q')->value);
    }

    public function test_advisory_thresholds_must_say_on_what_and_when(): void
    {
        $this->expectException(ToliConfigError::class);

        Thresholds::advisory(0.6, '', '');
    }

    public function test_a_missing_key_fails_before_the_network(): void
    {
        $http = new FakeHttpClient([]);

        try {
            new Toli('', $http, $http, $http);
            $this->fail('An empty key must be refused.');
        } catch (ToliConfigError) {
            $this->assertCount(0, $http->requests);
        }
    }

    public function test_asking_nothing_fails_before_the_network(): void
    {
        $http = new FakeHttpClient([]);

        try {
            $this->toli($http)->ask('s', []);
            $this->fail('Asking no question must be refused.');
        } catch (ToliConfigError) {
            $this->assertCount(0, $http->requests);
        }
    }

    public function test_a_reading_logs_the_decision_but_never_the_state(): void
    {
        $reading = $this->read([
            'model' => 'toli-1.13',
            'answers' => ['q' => ['choice' => 'sync', 'confidence' => 0.92, 'probabilities' => ['sync' => 0.92, 'other' => 0.08]]],
        ]);

        $log = $reading->toLog();

        $this->assertSame('toli-1.13', $log['model']);
        $this->assertSame('act', $log['answers']['q']['route']);
        $this->assertSame(['sync' => 0.92, 'other' => 0.08], $log['answers']['q']['probabilities']);
        $this->assertArrayHasKey('thresholds', $log, 'Replaying a decision needs the thresholds that made it.');
        $this->assertStringNotContainsString('state', json_encode($log, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $body */
    private function read(array $body, string $requested = 'toli-1'): Reading
    {
        return Reading::parse($body + ['model' => $requested], $requested, Thresholds::defaults());
    }

    private function toli(FakeHttpClient $http, ?callable $sleeper = null): Toli
    {
        return new Toli(
            apiKey: 'test-key',
            http: $http,
            requests: $http,
            streams: $http,
            sleeper: $sleeper ?? static function (int $s): void {},
        );
    }

    /** @return array<string, mixed> */
    private static function spec(string $file): array
    {
        $path = __DIR__.'/../../spec/conformance/'.$file;

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
