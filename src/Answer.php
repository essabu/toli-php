<?php

declare(strict_types=1);

namespace Essabu\Toli;

use Essabu\Toli\Exception\ToliProtocolError;

/**
 * Toli's answer to ONE question, and the route it calls for.
 *
 * `certainty` is the quantity the route is decided on. For a Choice or a Score
 * it is the confidence the model returned. A Noul carries no confidence: there
 * it is the distance from doubt — see `fromNoul()`.
 */
final readonly class Answer
{
    /**
     * @param  string  $kind  `choice`, `score`, `noul`, or any kind the catalogue lists
     * @param  string|float|array<string, mixed>|null  $value
     * @param  array<string, float>  $probabilities
     * @param  array<string, mixed>  $raw  the answer as served, for a kind with no typed reading
     */
    private function __construct(
        public string $kind,
        public string|float|array|null $value,
        public float $certainty,
        public Route $route,
        public array $probabilities = [],
        public ?string $legend = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     * @param  string|null  $askedKind  the kind the question was, when the caller knows it
     */
    public static function parse(string $name, array $raw, Thresholds $thresholds, ?string $askedKind = null): self
    {
        if (array_key_exists('choice', $raw)) {
            $confidence = self::float($raw, 'confidence');

            return new self(
                kind: 'choice',
                value: (string) $raw['choice'],
                certainty: $confidence,
                route: $thresholds->route($confidence),
                probabilities: self::probabilities($raw),
            );
        }

        if (array_key_exists('score', $raw)) {
            $confidence = self::float($raw, 'confidence');

            return new self(
                kind: 'score',
                value: (float) $raw['score'],
                certainty: $confidence,
                route: $thresholds->route($confidence),
                legend: isset($raw['legend']) ? (string) $raw['legend'] : null,
            );
        }

        if (array_key_exists('noul', $raw)) {
            return self::fromNoul((float) $raw['noul'], $thresholds);
        }

        // A kind this SDK has no typed reading for. It used to be a protocol
        // error, which made an SDK release the thing standing between a
        // customer and a kind the gateway already served. Now: the answer as
        // served, certainty from `confidence` when the engine gives one, and
        // ESCALATE when it does not — a route decided on nothing is a route
        // decided by a person.
        if ($askedKind === null || $askedKind === '') {
            throw new ToliProtocolError("Answer \"{$name}\" has an unknown shape and no kind was asked for it.");
        }

        $confidence = array_key_exists('confidence', $raw) ? self::float($raw, 'confidence') : null;
        $value = $raw;
        unset($value['confidence']);

        return new self(
            kind: $askedKind,
            value: count($value) === 1 ? reset($value) : $value,
            certainty: $confidence ?? 0.0,
            route: $confidence === null ? Route::Escalate : $thresholds->route($confidence),
            raw: $raw,
        );
    }

    /**
     * A Noul returns no confidence: 0.5 is the admission of ignorance, while
     * 0.02 and 0.98 are both frank answers. Certainty is therefore the distance
     * from doubt, rescaled onto [0, 1] — without which a confident "no" would
     * rank below an "I don't know".
     */
    private static function fromNoul(float $noul, Thresholds $thresholds): self
    {
        $certainty = abs($noul - 0.5) * 2.0;

        return new self(
            kind: 'noul',
            value: $noul,
            certainty: $certainty,
            route: $thresholds->route($certainty),
        );
    }

    /**
     * The answer as it came over the wire, rebuilt from what was parsed.
     *
     * @return array<string, mixed>
     */
    public function wire(): array
    {
        if ($this->raw !== []) {
            // A kind with no typed reading carries its name alongside, so a
            // store can hand the row back to `Reading::parse` knowing what
            // it was. The three built-ins are told apart by their own field.
            return $this->raw + ['_kind' => $this->kind];
        }

        return match ($this->kind) {
            'choice' => ['choice' => $this->value, 'confidence' => $this->certainty, 'probabilities' => $this->probabilities],
            'score' => ['score' => $this->value, 'confidence' => $this->certainty] + ($this->legend !== null ? ['legend' => $this->legend] : []),
            'noul' => ['noul' => $this->value],
            default => $this->raw,
        };
    }

    /** True when Toli leans towards yes — only meaningful for a Noul. */
    public function isYes(): bool
    {
        return $this->kind === 'noul' && (float) $this->value > 0.5;
    }

    /**
     * Enough to replay the decision six months later. The state is not in
     * there: what gets logged is the decision, not what was sent.
     *
     * @return array<string, mixed>
     */
    public function toLog(): array
    {
        return [
            'kind' => $this->kind,
            'value' => $this->value,
            'certainty' => $this->certainty,
            'route' => $this->route->value,
            'probabilities' => $this->probabilities,
            'legend' => $this->legend,
            'raw' => $this->raw === [] ? null : $this->raw,
        ];
    }

    /** @param array<string, mixed> $raw */
    private static function float(array $raw, string $key): float
    {
        return isset($raw[$key]) && is_numeric($raw[$key]) ? (float) $raw[$key] : 0.0;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, float>
     */
    private static function probabilities(array $raw): array
    {
        if (! isset($raw['probabilities']) || ! is_array($raw['probabilities'])) {
            return [];
        }

        $out = [];
        foreach ($raw['probabilities'] as $option => $p) {
            $out[(string) $option] = is_numeric($p) ? (float) $p : 0.0;
        }

        return $out;
    }
}
