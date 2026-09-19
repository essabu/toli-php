<?php

declare(strict_types=1);

namespace Essabu\Toli\Question;

/**
 * A statement: is it true?
 *
 * What comes back is the probability of yes — and NOT a confidence. 0.5 is the
 * admission of ignorance; 0.02 and 0.98 are both frank answers. The SDK derives
 * certainty from it (distance from doubt) to pick the route.
 */
final readonly class Noul implements Question
{
    public function __construct(public string $instructions) {}

    public function toArray(): array
    {
        return ['kind' => 'noul', 'instructions' => $this->instructions];
    }
}
