<?php

declare(strict_types=1);

namespace Essabu\Toli;

use Essabu\Toli\Exception\ToliConfigError;

/**
 * The thresholds that decide between the three routes.
 *
 * A threshold holds for ONE model, ONE set of questions, ONE corpus. Lifting it
 * from another project without re-measuring is flying blind — hence
 * `measuredOn` and `measuredAt`, required as soon as you depart from the
 * defaults: whoever reads `act: 0.9` six months from now must be able to tell
 * what that 0.9 was established on, and when.
 */
final readonly class Thresholds
{
    private function __construct(
        public float $act,
        public float $confirm,
        public string $measuredOn,
        public string $measuredAt,
        /** When true, no answer can ever reach Route::Act — see `advisory()`. */
        public bool $advisory = false,
    ) {}

    /**
     * MEASURED thresholds: say on what, and when.
     *
     * @param  string  $measuredOn  the corpus — "896 do-alo tickets, French"
     * @param  string  $measuredAt  the date — "2026-09-18"
     */
    public static function measured(float $act, float $confirm, string $measuredOn, string $measuredAt): self
    {
        if ($measuredOn === '' || $measuredAt === '') {
            throw new ToliConfigError('Measured thresholds state what they were measured on and when: measuredOn and measuredAt are required.');
        }

        return self::guard(new self($act, $confirm, $measuredOn, $measuredAt));
    }

    /**
     * The contract defaults, NOT measured on your data. Fine to start with,
     * wrong to decide with: measure, then move to `measured()`.
     */
    public static function defaults(): self
    {
        return new self(0.85, 0.60, 'contract defaults — not measured on your data', '');
    }

    /**
     * ADVISORY thresholds: Toli may propose, never act.
     *
     * For any domain where acting alone would be irreversible or unsafe —
     * clinical orientation, screening for sickle-cell disease, haemophilia or
     * rare diseases, anything touching a person's care. There, a wrong answer
     * is not re-routed the next morning.
     *
     * The guarantee is structural, not a matter of picking a high threshold:
     * `route()` cannot return Route::Act, whatever the model's confidence. The
     * most a reading can earn is CONFIRM — a proposal a qualified human
     * accepts or rejects.
     *
     * @param  float  $confirm  below this, the answer is escalated rather than shown
     */
    public static function advisory(float $confirm, string $measuredOn, string $measuredAt): self
    {
        if ($measuredOn === '' || $measuredAt === '') {
            throw new ToliConfigError('Advisory thresholds state what they were measured on and when: measuredOn and measuredAt are required.');
        }

        if ($confirm <= 0.0 || $confirm > 1.0) {
            throw new ToliConfigError("Confirm threshold out of bounds: 0 < confirm <= 1 (got {$confirm}).");
        }

        return new self(act: 1.0, confirm: $confirm, measuredOn: $measuredOn, measuredAt: $measuredAt, advisory: true);
    }

    /** The route a given certainty calls for. Both thresholds are INCLUSIVE. */
    public function route(float $certainty): Route
    {
        if ($this->advisory) {
            // No amount of confidence buys an automatic action here.
            return $certainty >= $this->confirm ? Route::Confirm : Route::Escalate;
        }

        return match (true) {
            $certainty >= $this->act => Route::Act,
            $certainty >= $this->confirm => Route::Confirm,
            default => Route::Escalate,
        };
    }

    /** @return array{act: float, confirm: float, measured_on: string, measured_at: string, advisory: bool} */
    public function toArray(): array
    {
        return [
            'act' => $this->act,
            'confirm' => $this->confirm,
            'measured_on' => $this->measuredOn,
            'measured_at' => $this->measuredAt,
            'advisory' => $this->advisory,
        ];
    }

    private static function guard(self $t): self
    {
        if ($t->act > 1.0 || $t->confirm <= 0.0) {
            throw new ToliConfigError("Thresholds out of bounds: 0 < confirm <= act <= 1 (got act={$t->act}, confirm={$t->confirm}).");
        }

        if ($t->confirm > $t->act) {
            throw new ToliConfigError("Confirm threshold above the act threshold: the 'propose' route would be unreachable (act={$t->act}, confirm={$t->confirm}).");
        }

        return $t;
    }
}
