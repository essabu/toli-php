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
     * @param  'choice'|'score'|'noul'  $kind
     * @param  array<string, float>  $probabilities
     */
    private function __construct(
        public string $kind,
        public string|float $value,
        public float $certainty,
        public Route $route,
        public array $probabilities = [],
        public ?string $legend = null,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function parse(string $name, array $raw, Thresholds $thresholds): self
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

        throw new ToliProtocolError("Answer \"{$name}\" has an unknown shape: neither choice, score, nor noul.");
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
