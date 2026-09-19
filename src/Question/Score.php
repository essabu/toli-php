<?php

declare(strict_types=1);

namespace Essabu\Toli\Question;

use Essabu\Toli\Exception\ToliConfigError;

/**
 * A degree on ORDERED, described levels, from weakest to strongest. What comes
 * back is a weighted float, not an index: "1.4" says something that "level 1"
 * does not.
 */
final readonly class Score implements Question
{
    /**
     * @param  list<string>  $criteria  levels, weakest to strongest
     */
    public function __construct(
        public string $instructions,
        public array $criteria,
    ) {
        if (count($criteria) < 2) {
            throw new ToliConfigError('A Score needs at least two ordered levels.');
        }
    }

    public function toArray(): array
    {
        return ['kind' => 'score', 'instructions' => $this->instructions, 'criteria' => $this->criteria];
    }
}
