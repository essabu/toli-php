<?php

declare(strict_types=1);

namespace Essabu\Toli\Question;

use Essabu\Toli\Exception\ToliConfigError;

/**
 * A question of a kind this SDK has no typed helper for — yet.
 *
 * The gateway's catalogue (`Toli::kinds()`) grows by registration, and an SDK
 * release cannot be the thing that gates a new kind. So anything the catalogue
 * lists can be asked through this: the kind, the instructions, and whatever
 * fields the catalogue says that kind carries, sent exactly as given.
 *
 * It refuses nothing the gateway would accept, and asserts nothing the
 * gateway would not: validation of a generic question is the gateway's job,
 * because the gateway is what knows the kind.
 */
final readonly class Generic implements Question
{
    /**
     * @param  array<string, mixed>  $fields  the kind's own fields, per the catalogue
     */
    public function __construct(
        public string $kind,
        public string $instructions,
        public array $fields = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,31}$/', $kind) !== 1) {
            throw new ToliConfigError("A kind is a lowercase snake_case name, got '{$kind}'.");
        }

        if (trim($instructions) === '') {
            throw new ToliConfigError('A question without instructions asks nothing.');
        }

        foreach (['kind', 'instructions'] as $reserved) {
            if (array_key_exists($reserved, $fields)) {
                throw new ToliConfigError("'{$reserved}' is not a field: it is the question's own {$reserved}.");
            }
        }
    }

    /**
     * The same thing, read as a sentence.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function of(string $kind, string $instructions, array $fields = []): self
    {
        return new self($kind, $instructions, $fields);
    }

    public function toArray(): array
    {
        return ['kind' => $this->kind, 'instructions' => $this->instructions] + $this->fields;
    }
}
