<?php

declare(strict_types=1);

namespace Essabu\Toli\Transport;

/**
 * The Essabu gateway — the only public path.
 *
 * It holds the provider key, carries quotas, billing and the audit log, and
 * lets the engine underneath change without touching a single SDK. Callers
 * never see a provider name, and have no URL to configure.
 */
final readonly class Gateway implements Transport
{
    public const DEFAULT_BASE = 'https://toli.essabu.com';

    public function __construct(private string $base = self::DEFAULT_BASE) {}

    public function url(): string
    {
        return rtrim($this->base, '/').'/v1/ask';
    }

    public function body(string|array $state, array $questions, string $model): array
    {
        return ['state' => $state, 'model' => $model, 'questions' => $questions];
    }
}
