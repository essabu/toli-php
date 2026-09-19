<?php

declare(strict_types=1);

namespace Essabu\Toli;

/**
 * The three routes — the heart of Toli.
 *
 * Toli never decides alone: it returns a route, and the caller acts. A library
 * that applied the decision on the caller's behalf would remove the human
 * guardrail without anyone having chosen to.
 */
enum Route: string
{
    /** Sure enough for the code to act on its own. */
    case Act = 'act';

    /** Likely: propose it, a human approves in one click. */
    case Confirm = 'confirm';

    /** Toli does not know. Human queue, or an LLM to narrow it down. */
    case Escalate = 'escalate';
}
