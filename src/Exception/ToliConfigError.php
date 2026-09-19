<?php

declare(strict_types=1);

namespace Essabu\Toli\Exception;

use InvalidArgumentException;

/**
 * Malformed call: missing key, inconsistent thresholds, impossible question.
 *
 * Deliberately NOT a ToliException: a caller that catches Toli errors in order
 * to retry must not silently swallow its own configuration bugs.
 */
final class ToliConfigError extends InvalidArgumentException {}
