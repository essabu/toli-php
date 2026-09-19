<?php

declare(strict_types=1);

namespace Essabu\Toli\Exception;

/** Key rejected (401 / 403). No retry: the same key will be rejected the second time. */
final class ToliAuthError extends ToliException {}
