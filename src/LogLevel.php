<?php

declare(strict_types=1);

namespace Rapira;

/**
 * Severity of a log message. These five are what Rapira records — there is no level below `Trace` and
 * nothing above `Error`, so a record cannot ask for a severity the sink has no way to write.
 *
 * PSR-3 has eight levels, so a bridge squashes. The preferred mapping:
 *
 *     emergency, alert, critical, error → Error
 *     warning                           → Warning
 *     notice, info                      → Info
 *     debug                             → Debug
 *
 * Write it out as a `match`. Unbacked is deliberate — the host matches cases and nothing on the wire needs
 * the string.
 *
 * The squash is lossy: `emergency` and `error` become one level, and paging usually distinguishes them.
 * Keep the original severity in the log context when that difference matters.
 */
enum LogLevel
{
    case Error;
    case Warning;
    case Info;
    case Debug;

    /** Below `Debug`: per-iteration detail, wire dumps, anything too noisy to leave enabled. */
    case Trace;
}
