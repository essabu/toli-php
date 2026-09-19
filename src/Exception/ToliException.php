<?php

declare(strict_types=1);

namespace Essabu\Toli\Exception;

use RuntimeException;

/**
 * Root of the Toli errors. Carries enough to diagnose without spilling the
 * state into a log file: the HTTP status, the server's request id, and a body
 * excerpt CAPPED at 300 characters.
 */
abstract class ToliException extends RuntimeException
{
    public const BODY_EXCERPT_MAX = 300;

    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $requestId = null,
        public readonly ?string $bodyExcerpt = null,
    ) {
        parent::__construct($message);
    }

    /** Cuts a response body down to what we accept to copy into a log. */
    public static function excerpt(string $body): string
    {
        return mb_substr($body, 0, self::BODY_EXCERPT_MAX);
    }
}
