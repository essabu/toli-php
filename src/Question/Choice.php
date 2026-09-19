<?php

declare(strict_types=1);

namespace Essabu\Toli\Question;

use Essabu\Toli\Exception\ToliConfigError;

/**
 * One option out of a CLOSED set.
 *
 * Always leave an escape hatch ("other", "none"…): without one the model is
 * forced to pick between boxes that do not describe the case, and the
 * confidence it returns stops meaning anything.
 */
final readonly class Choice implements Question
{
    /**
     * @param  string  $instructions  what is being asked, preferably in English
     * @param  array<string, string>  $criteria  option => what it covers
     */
    public function __construct(
        public string $instructions,
        public array $criteria,
    ) {
        if (count($criteria) < 2) {
            throw new ToliConfigError('A Choice with fewer than two options leaves no choice: add at least an escape hatch ("other").');
        }
    }

    public function toArray(): array
    {
        return ['kind' => 'choice', 'instructions' => $this->instructions, 'criteria' => $this->criteria];
    }
}
