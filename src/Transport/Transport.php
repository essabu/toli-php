<?php

declare(strict_types=1);

namespace Essabu\Toli\Transport;

/**
 * Where the request goes, and under which name its fields travel.
 *
 * Two implementations, one public: the gateway. The provider-direct transport
 * exists so teams can build before the gateway is deployed — and so that the
 * day the engine changes, only this folder moves.
 */
interface Transport
{
    /** Absolute URL to POST to. */
    public function url(): string;

    /**
     * The request body, in the shape this transport expects.
     *
     * @param  string|array<string, mixed>  $state
     * @param  array<string, array<string, mixed>>  $questions
     * @return array<string, mixed>
     */
    public function body(string|array $state, array $questions, string $model): array;
}
