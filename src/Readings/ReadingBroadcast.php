<?php

declare(strict_types=1);

namespace Essabu\Toli\Readings;

/**
 * Tells whoever is listening that a reading was recorded.
 *
 * A screen showing a ticket should not have to poll to learn that Toli has
 * read it. What is broadcast is the DECISION — subject, model, answers,
 * routes — never the state that was sent, which is the caller's data and not
 * the SDK's to repeat over a socket.
 */
interface ReadingBroadcast
{
    public function recorded(StoredReading $stored): void;
}
