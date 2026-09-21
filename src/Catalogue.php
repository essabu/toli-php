<?php

declare(strict_types=1);

namespace Essabu\Toli;

use Essabu\Toli\Exception\ToliProtocolError;

/**
 * What the gateway answers, as the gateway describes it.
 *
 * Read from `GET /v1/kinds`. The three built-ins — choice, score, noul — have
 * typed helpers in `Question\`; everything else is asked through
 * `Question\Generic`, with the fields this catalogue names. An SDK that
 * hard-coded the list would be the thing standing between a customer and a
 * kind the gateway already serves.
 */
final readonly class Catalogue
{
    /**
     * @param  list<array{name: string, kinds: list<string>}>  $categories
     * @param  array<string, array<string, mixed>>  $kinds  name => spec as served
     */
    public function __construct(
        public int $contractVersion,
        public array $categories,
        public array $kinds,
    ) {}

    /** @param array<string, mixed> $body */
    public static function parse(array $body): self
    {
        if (! isset($body['kinds']) || ! is_array($body['kinds'])) {
            throw new ToliProtocolError('Catalogue without "kinds": the server replied, but not with a catalogue.');
        }

        $kinds = [];
        foreach ($body['kinds'] as $spec) {
            if (is_array($spec) && isset($spec['name'])) {
                $kinds[(string) $spec['name']] = $spec;
            }
        }

        $categories = [];
        foreach ((array) ($body['categories'] ?? []) as $category) {
            if (is_array($category) && isset($category['name'])) {
                $categories[] = [
                    'name' => (string) $category['name'],
                    'kinds' => array_values(array_map('strval', (array) ($category['kinds'] ?? []))),
                ];
            }
        }

        return new self(
            contractVersion: (int) ($body['contract_version'] ?? 0),
            categories: $categories,
            kinds: $kinds,
        );
    }

    public function has(string $kind): bool
    {
        return isset($this->kinds[$kind]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->kinds);
    }

    /** @return list<string> the kinds under one category, in catalogue order */
    public function under(string $category): array
    {
        foreach ($this->categories as $c) {
            if ($c['name'] === $category) {
                return $c['kinds'];
            }
        }

        return [];
    }

    /** @return list<string> the fields a question of this kind must carry */
    public function required(string $kind): array
    {
        return array_values(array_map('strval', (array) ($this->kinds[$kind]['question']['required'] ?? [])));
    }

    /** `confidence`, `noul` or `none`: how certainty is derived for this kind. */
    public function certainty(string $kind): string
    {
        return (string) ($this->kinds[$kind]['certainty'] ?? 'none');
    }
}
