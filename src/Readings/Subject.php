<?php

declare(strict_types=1);

namespace Essabu\Toli\Readings;

use Essabu\Toli\Exception\ToliConfigError;

/**
 * The thing a reading is ABOUT: a ticket, a document, a call — named by the
 * caller's own type and identifier.
 *
 * Toli never sees it. What travels to the gateway is the state; the subject
 * is how the caller finds the reading again, and the key that makes "never
 * read twice" a rule rather than a hope.
 */
final readonly class Subject
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $type) !== 1) {
            throw new ToliConfigError("A subject type is a lowercase snake_case name, got '{$type}'.");
        }

        if (trim($id) === '' || mb_strlen($id) > 191) {
            throw new ToliConfigError('A subject id is a non-empty string of at most 191 characters.');
        }
    }

    public static function of(string $type, string|int $id): self
    {
        return new self($type, (string) $id);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    public function __toString(): string
    {
        return "{$this->type}:{$this->id}";
    }
}
