<?php

declare(strict_types=1);

namespace Rapira;

/**
 * A unit of work from {@see Dispatcher}: the data to act on plus the verbs that finish it.
 *
 * Instances come from the host, never from `new`. The finalizing verbs live on the concrete type —
 * HTTP writes a body, gRPC responds or fails, jobs completes or retries.
 *
 * Every unit must be finalized exactly once. Twice throws {@see Exception\AlreadyFinalizedError}; a unit
 * dropped unfinalized is caught by {@see self::__destruct()} and failed by the host. If the host closed
 * the unit first, finalizing throws {@see Exception\WorkDiscardedException}.
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

    /**
     * Safety net: dropping the last reference to an unfinalized unit reports the loss to the host,
     * which fails it — HTTP answers 500, gRPC ends with INTERNAL, a job goes to fail or retry per
     * queue policy. Does nothing when the unit is already finalized or discarded.
     *
     * A lost unit is always a failure, never an implicit response — and a late one: objects held in
     * reference cycles wait for the cycle collector, and a fatal error skips destructors entirely,
     * leaving the loss to the host's own deadline.
     */
    public function __destruct();
}
