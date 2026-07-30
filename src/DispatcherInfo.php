<?php

declare(strict_types=1);

namespace Rapira;

/**
 * Live dispatcher counters.
 *
 * Immutable snapshot: every value is captured when the instance is created, so the counters are
 * mutually consistent. Observability only — never a control-flow source.
 *
 * Plugins narrow {@see Dispatcher::getInfo()} to their own descendant of this interface.
 */
interface DispatcherInfo
{
    /** @return int<0, max> Units the plugin holds pending, not yet handed to any worker. */
    public function pendingCount(): int;

    /** @return int<0, max> Units handed to this worker as {@see Work} and not yet finalized. */
    public function activeCount(): int;
}
