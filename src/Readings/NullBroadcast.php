<?php

declare(strict_types=1);

namespace Essabu\Toli\Readings;

/** For a caller with nothing listening. */
final class NullBroadcast implements ReadingBroadcast
{
    public function recorded(StoredReading $stored): void {}
}
