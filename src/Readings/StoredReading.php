<?php

declare(strict_types=1);

namespace Essabu\Toli\Readings;

use DateTimeImmutable;
use Essabu\Toli\Reading;

/** A reading, kept: what was read, about what, by which model, and when. */
final readonly class StoredReading
{
    public function __construct(
        public Subject $subject,
        public string $model,
        public Reading $reading,
        public DateTimeImmutable $readAt,
        /** True when this call did NOT go to Toli: the store already had it. */
        public bool $fromStore = false,
    ) {}
}
