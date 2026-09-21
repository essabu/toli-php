<?php

declare(strict_types=1);

namespace Essabu\Toli\Transport;

/**
 * Straight to the upstream provider, with ITS key.
 *
 * FOR LOCAL DEVELOPMENT ONLY, until the gateway is deployed. Shipping this in
 * production would hand the provider key to every integration and tie the
 * public API to one engine — exactly what the gateway exists to prevent.
 */
final readonly class Direct implements Transport
{
    public function __construct(private string $base) {}

    public function url(): string
    {
        return rtrim($this->base, '/').'/v1/systemone';
    }

    /** The provider has no catalogue: the kinds are the gateway's contract. */
    public function kindsUrl(): ?string
    {
        return null;
    }

    public function body(string|array $state, array $questions, string $model): array
    {
        return ['state' => $state, 'model' => $model, 'questions' => $questions];
    }
}
