<?php

declare(strict_types=1);

namespace Essabu\Toli\Exception;

/** Service unavailable (5xx, 529) or dropped connection. Transient: the SDK already retried. */
final class ToliUnavailableError extends ToliException {}
