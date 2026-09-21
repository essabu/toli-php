<?php

declare(strict_types=1);

namespace Essabu\Toli\Readings;

use Essabu\Toli\Question\Question;
use Essabu\Toli\Toli;

/**
 * Read a subject once, keep it, tell the room.
 *
 * This is the unit of behaviour every integration was rewriting for itself:
 * check the store, call Toli if it has nothing, save what came back,
 * broadcast it. Written once here, it is the same in every application that
 * installs the framework package — and the store is what makes "once" true.
 */
final readonly class Reader
{
    public function __construct(
        private Toli $toli,
        private ReadingStore $store,
        private ReadingBroadcast $broadcast = new NullBroadcast,
    ) {}

    /**
     * @param  string|array<string, mixed>  $state  what Toli is given to judge
     * @param  array<string, Question>  $questions
     * @param  bool  $again  read even if a reading is already kept — a re-run
     *                       after the questions changed, never the default
     */
    public function read(
        Subject $subject,
        string|array $state,
        array $questions,
        ?string $model = null,
        bool $again = false,
    ): StoredReading {
        $model ??= $this->toli->defaultModel();

        if (! $again) {
            $kept = $this->store->find($subject, $model);
            if ($kept instanceof StoredReading) {
                return new StoredReading($kept->subject, $kept->model, $kept->reading, $kept->readAt, fromStore: true);
            }
        }

        $reading = $this->toli->ask($state, $questions, $model);

        // Kept under the model that was ASKED, not the one the server
        // reports: `toli-1` may be served by `toli-1.14` today and `toli-1.15`
        // next month, and the rule is one reading per subject and pinned
        // model, or a minor upstream release would re-read everything.
        $stored = $this->store->save($subject, $model, $reading);
        $this->broadcast->recorded($stored);

        return $stored;
    }

    /** Whether a reading is already kept, without making one. */
    public function has(Subject $subject, ?string $model = null): bool
    {
        return $this->store->find($subject, $model ?? $this->toli->defaultModel()) instanceof StoredReading;
    }
}
