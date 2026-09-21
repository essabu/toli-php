<?php

declare(strict_types=1);

namespace Essabu\Toli\Readings;

use Essabu\Toli\Reading;

/**
 * Where readings are kept — the one port a framework package implements.
 *
 * The rule it exists to enforce: ONE reading per subject and model. A
 * second call for the same pair returns the first, and never reaches the
 * gateway. That is what keeps the cost of a re-run at zero and the analysis
 * stable under it.
 *
 * The canonical table is described in `spec/readings.md`. A framework
 * package ships it as a migration; this core ships the contract.
 */
interface ReadingStore
{
    /** The reading kept for this subject under this model, if any. */
    public function find(Subject $subject, string $model): ?StoredReading;

    /** Keeps a reading, replacing any earlier one for the same subject and model. */
    public function save(Subject $subject, string $model, Reading $reading): StoredReading;

    /** Drops the reading for this subject under this model, so the next call reads afresh. */
    public function forget(Subject $subject, string $model): void;
}
