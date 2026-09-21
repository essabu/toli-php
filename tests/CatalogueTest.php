<?php

declare(strict_types=1);

namespace Essabu\Toli\Tests;

use Essabu\Toli\Exception\ToliConfigError;
use Essabu\Toli\Question\Choice;
use Essabu\Toli\Question\Generic;
use Essabu\Toli\Route;
use Essabu\Toli\Toli;
use Essabu\Toli\Transport\Direct;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue and the generic question: how the SDK asks a kind it was not
 * compiled with.
 *
 * The property under test is that a kind registered on the gateway is usable
 * from this SDK WITHOUT a release of this SDK. Every test here would have
 * failed under contract version 1, where the three kinds were the boundary.
 */
final class CatalogueTest extends TestCase
{
    private const CATALOGUE = [
        'contract_version' => 2,
        'categories' => [
            ['name' => 'decision', 'kinds' => ['choice', 'noul']],
            ['name' => 'measure', 'kinds' => ['score']],
            ['name' => 'extraction', 'kinds' => ['extract']],
            ['name' => 'ordering', 'kinds' => []],
        ],
        'kinds' => [
            ['name' => 'choice', 'category' => 'decision', 'question' => ['required' => ['instructions', 'criteria'], 'optional' => []], 'answer' => ['choice', 'confidence', 'probabilities'], 'certainty' => 'confidence', 'since' => 1],
            ['name' => 'noul', 'category' => 'decision', 'question' => ['required' => ['instructions'], 'optional' => []], 'answer' => ['noul'], 'certainty' => 'noul', 'since' => 1],
            ['name' => 'score', 'category' => 'measure', 'question' => ['required' => ['instructions'], 'optional' => ['criteria']], 'answer' => ['score', 'confidence', 'legend'], 'certainty' => 'confidence', 'since' => 1],
            ['name' => 'extract', 'category' => 'extraction', 'question' => ['required' => ['instructions', 'fields'], 'optional' => []], 'answer' => ['extract', 'confidence'], 'certainty' => 'confidence', 'since' => 3],
        ],
    ];

    public function test_the_catalogue_is_read_from_the_gateway_with_a_get(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok(self::CATALOGUE)]);

        $catalogue = $this->toli($http)->kinds();

        $request = $http->requests[0];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('https://toli.essabu.com/v1/kinds', (string) $request->getUri());
        $this->assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));

        $this->assertSame(2, $catalogue->contractVersion);
        $this->assertSame(['choice', 'noul', 'score', 'extract'], $catalogue->names());
        $this->assertSame(['choice', 'noul'], $catalogue->under('decision'));
        $this->assertSame([], $catalogue->under('ordering'), 'A declared, empty category is still a category.');
        $this->assertSame(['instructions', 'fields'], $catalogue->required('extract'));
        $this->assertSame('noul', $catalogue->certainty('noul'));
    }

    public function test_the_provider_direct_transport_has_no_catalogue(): void
    {
        $http = new FakeHttpClient([]);
        $toli = new Toli('provider-key', $http, $http, $http, transport: new Direct('https://api.example'));

        $this->expectException(ToliConfigError::class);
        $toli->kinds();
    }

    public function test_a_generic_question_travels_with_its_own_fields_untouched(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok([
            'model' => 'toli-1',
            'answers' => ['dates' => ['extract' => ['signed' => '2026-09-21'], 'confidence' => 0.91]],
        ])]);

        $this->toli($http)->ask('Signed on 21 September 2026.', [
            'dates' => Generic::of('extract', 'Pull out the dates.', ['fields' => ['signed']]),
        ]);

        $body = json_decode((string) $http->requests[0]->getBody(), true);
        $this->assertSame(
            ['kind' => 'extract', 'instructions' => 'Pull out the dates.', 'fields' => ['signed']],
            $body['questions']['dates'],
        );
    }

    public function test_an_answer_of_an_unknown_shape_is_read_as_the_kind_that_was_asked(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok([
            'model' => 'toli-1',
            'answers' => ['dates' => ['extract' => ['signed' => '2026-09-21'], 'confidence' => 0.91]],
        ])]);

        $reading = $this->toli($http)->ask('Signed on 21 September 2026.', [
            'dates' => Generic::of('extract', 'Pull out the dates.', ['fields' => ['signed']]),
        ]);

        $answer = $reading->answer('dates');
        $this->assertSame('extract', $answer->kind);
        $this->assertSame(['signed' => '2026-09-21'], $answer->value);
        $this->assertSame(0.91, $answer->certainty);
        $this->assertSame(Route::Act, $answer->route);
        $this->assertSame(['extract' => ['signed' => '2026-09-21'], 'confidence' => 0.91], $answer->raw);
    }

    public function test_an_answer_without_confidence_escalates_rather_than_guesses(): void
    {
        // A route decided on nothing is a route decided by a person.
        $http = new FakeHttpClient([FakeHttpClient::ok([
            'model' => 'toli-1',
            'answers' => ['order' => ['rank' => ['b', 'a', 'c']]],
        ])]);

        $reading = $this->toli($http)->ask('Three items.', [
            'order' => Generic::of('rank', 'Order them by urgency.', ['items' => ['a', 'b', 'c']]),
        ]);

        $this->assertSame(Route::Escalate, $reading->answer('order')->route);
        $this->assertSame(0.0, $reading->answer('order')->certainty);
    }

    public function test_the_typed_helpers_are_unchanged_by_the_generic_path(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok([
            'model' => 'toli-1',
            'answers' => ['team' => ['choice' => 'x', 'confidence' => 0.9, 'probabilities' => ['x' => 0.9, 'other' => 0.1]]],
        ])]);

        $reading = $this->toli($http)->ask('a state', [
            'team' => new Choice('Which team', ['x' => 'X', 'other' => 'None of the above']),
        ]);

        $this->assertSame('choice', $reading->answer('team')->kind);
        $this->assertSame('x', $reading->answer('team')->value);
        $this->assertSame([], $reading->answer('team')->raw, 'A typed kind carries no raw payload.');
    }

    public function test_a_generic_question_refuses_to_smuggle_its_own_kind_as_a_field(): void
    {
        $this->expectException(ToliConfigError::class);
        Generic::of('extract', 'Pull out the dates.', ['kind' => 'choice']);
    }

    private function toli(FakeHttpClient $http): Toli
    {
        return new Toli('test-key', $http, $http, $http, sleeper: static fn (): null => null);
    }
}
