<?php

declare(strict_types=1);

namespace Essabu\Toli\Exception;

/** Quota exceeded (429). Transient: the SDK already retried, honouring the server's retry-after. */
final class ToliRateLimitError extends ToliException {}
