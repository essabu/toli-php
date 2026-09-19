<?php

declare(strict_types=1);

namespace Essabu\Toli\Question;

/**
 * A typed question — the only way to ask Toli anything.
 *
 * Three forms, no more: a closed choice, an ordered degree, a statement to
 * check. Everything else belongs to the calling code.
 */
interface Question
{
    /** @return array<string, mixed> the body put on the wire */
    public function toArray(): array;
}
