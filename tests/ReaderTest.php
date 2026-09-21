<?php

declare(strict_types=1);

namespace Essabu\Toli\Tests;

use DateTimeImmutable;
use Essabu\Toli\Question\Choice;
use Essabu\Toli\Question\Noul;
use Essabu\Toli\Question\Question;
use Essabu\Toli\Reading;
use Essabu\Toli\Readings\Reader;
use Essabu\Toli\Readings\ReadingBroadcast;
use Essabu\Toli\Readings\ReadingStore;
use Essabu\Toli\Readings\StoredReading;
use Essabu\Toli\Readings\Subject;
use Essabu\Toli\Thresholds;
use Essabu\Toli\Toli;
use PHPUnit\Framework\TestCase;

/**
 * "Read once, keep it, tell the room" — the behaviour every integration was
 * rewriting for itself, held here against an in-memory store.
 */
final class ReaderTest extends TestCase
{
    private const ANSWER = ['model' => 'toli-1.14', 'answers' => [
        'team' => ['choice' => 'app', 'confidence' => 0.9, 'probabilities' => ['app' => 0.9, 'other' => 0.1]],
        'urgent' => ['noul' => 0.8],
    ], 'usage' => ['input_tokens' => 120, 'output_tokens' => 0]];

    public function test_the_first_call_reads_and_keeps_and_broadcasts(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok(self::ANSWER)]);
        $store = new MemoryStore;
        $room = new Room;
        $reader = new Reader($this->toli($http), $store, $room);

        $stored = $reader->read(Subject::of('ticket', 'TK-1'), 'A state.', $this->questions());

        $this->assertFalse($stored->fromStore);
        $this->assertSame('toli-1', $stored->model, 'Kept under the model ASKED, not the one served.');
        $this->assertSame('toli-1.14', $stored->reading->model, 'The reading itself says what served it.');
        $this->assertSame('app', $stored->reading->value('team'));
        $this->assertCount(1, $room->heard);
        $this->assertCount(1, $http->requests);
    }

    public function test_the_second_call_never_reaches_the_gateway(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok(self::ANSWER)]);
        $store = new MemoryStore;
        $room = new Room;
        $reader = new Reader($this->toli($http), $store, $room);

        $reader->read(Subject::of('ticket', 'TK-1'), 'A state.', $this->questions());
        $again = $reader->read(Subject::of('ticket', 'TK-1'), 'A state, edited.', $this->questions());

        $this->assertTrue($again->fromStore);
        $this->assertCount(1, $http->requests, 'One reading per subject and model: the second call is served from the store.');
        $this->assertCount(1, $room->heard, 'Nothing new happened, so nothing is broadcast.');
        $this->assertSame('app', $again->reading->value('team'));
    }

    public function test_asking_again_is_explicit_and_replaces(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::ok(self::ANSWER),
            FakeHttpClient::ok(['model' => 'toli-1.14', 'answers' => ['team' => ['choice' => 'other', 'confidence' => 0.7, 'probabilities' => ['app' => 0.3, 'other' => 0.7]], 'urgent' => ['noul' => 0.2]]]),
        ]);
        $store = new MemoryStore;
        $reader = new Reader($this->toli($http), $store);

        $reader->read(Subject::of('ticket', 'TK-1'), 'A state.', $this->questions());
        $second = $reader->read(Subject::of('ticket', 'TK-1'), 'A state, with a comment.', $this->questions(), again: true);

        $this->assertFalse($second->fromStore);
        $this->assertCount(2, $http->requests);
        $this->assertSame('other', $store->find(Subject::of('ticket', 'TK-1'), 'toli-1')?->reading->value('team'));
    }

    public function test_a_different_model_is_a_different_reading(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok(self::ANSWER), FakeHttpClient::ok(self::ANSWER)]);
        $reader = new Reader($this->toli($http), new MemoryStore);

        $reader->read(Subject::of('ticket', 'TK-1'), 'A state.', $this->questions(), 'toli-1');
        $reader->read(Subject::of('ticket', 'TK-1'), 'A state.', $this->questions(), 'toli-2');

        $this->assertCount(2, $http->requests);
    }

    public function test_a_kept_reading_round_trips_through_its_wire_form(): void
    {
        // What a store keeps is the wire shape, so a later SDK can re-read an
        // old row: the parsed form would freeze this SDK's reading of it.
        $reading = Reading::parse(self::ANSWER, 'toli-1', Thresholds::defaults());

        $back = Reading::parse($reading->toArray(), 'toli-1', Thresholds::defaults(), $reading->kinds());

        $this->assertSame('app', $back->value('team'));
        $this->assertSame(0.8, $back->value('urgent'));
        $this->assertSame($reading->route('team'), $back->route('team'));
        $this->assertSame(['input_tokens' => 120, 'output_tokens' => 0], $back->usage);
    }

    /** @return array<string, Question> */
    private function questions(): array
    {
        return [
            'team' => new Choice('Which team', ['app' => 'The app', 'other' => 'None']),
            'urgent' => new Noul('Still blocked'),
        ];
    }

    private function toli(FakeHttpClient $http): Toli
    {
        return new Toli('test-key', $http, $http, $http, sleeper: static fn (): null => null);
    }
}

/** The contract, in memory. */
final class MemoryStore implements ReadingStore
{
    /** @var array<string, StoredReading> */
    private array $rows = [];

    public function find(Subject $subject, string $model): ?StoredReading
    {
        return $this->rows["{$subject}|{$model}"] ?? null;
    }

    public function save(Subject $subject, string $model, Reading $reading): StoredReading
    {
        return $this->rows["{$subject}|{$model}"] = new StoredReading($subject, $model, $reading, new DateTimeImmutable);
    }

    public function forget(Subject $subject, string $model): void
    {
        unset($this->rows["{$subject}|{$model}"]);
    }
}

final class Room implements ReadingBroadcast
{
    /** @var list<StoredReading> */
    public array $heard = [];

    public function recorded(StoredReading $stored): void
    {
        $this->heard[] = $stored;
    }
}
