<?php

declare(strict_types=1);

namespace Essabu\Toli\Exception;

/** The server answered, but not with what was expected: unreadable JSON, or a response without "answers". */
final class ToliProtocolError extends ToliException {}
