<?php

declare(strict_types=1);

namespace Rapira;

/**
 * A unit of work from {@see Dispatcher}: the data to act on plus the verbs that finish it.
 *
 * Instances come from the host, never from `new`. The finalizing verbs live on the concrete type —
 * HTTP writes a body, gRPC responds or fails, jobs completes or retries.
 *
 * Every unit must be finalized exactly once. Twice throws {@see Exception\AlreadyFinalizedError}; not
 * at all leaves it to the host, which fails the unit and recycles the worker. If the host closed the
 * unit first, finalizing throws {@see Exception\WorkDiscardedException}.
 */
interface Work
{
    /** Whether the outcome is committed — by the worker, or by the host on deadline, drain or a gone client. */
    public function isFinalized(): bool;

    /**
     * Whether the outcome will no longer be accepted: deadline passed, client disconnected, delivery
     * lease lost to another worker.
     *
     * Cooperative — nothing interrupts a handler, so long work asks at its own checkpoints.
     */
    public function isCancelled(): bool;
}
