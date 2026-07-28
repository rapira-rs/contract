<?php

declare(strict_types=1);

namespace Rapira;

use Rapira\Exception\ClosedException;
use Rapira\Exception\TimeoutException;

/**
 * The plugin surface this worker's pool serves. Obtain it from {@see get_dispatcher()}.
 *
 * ```php
 * $dispatcher = \Rapira\get_dispatcher();
 *
 * try {
 *     while (true) {
 *         $work = $dispatcher->receive(1_000_000);
 *         // $work carries the request data and the verbs that finalize it
 *     }
 * } catch (ClosedException) {
 *     // drained: finish what is in flight and leave
 * }
 * ```
 *
 * Plugins narrow {@see self::receive()}, {@see self::tryReceive()} and {@see self::info()} to their
 * own types.
 */
interface Dispatcher
{
    /**
     * Plugin identity, matching its root TOML section: "grpc", "jobs", "http".
     *
     * @return non-empty-string
     */
    public function name(): string;

    /**
     * Take a unit of work if one is available right now. Never blocks.
     *
     * @return Work|null Null means nothing is available at this moment; the queue may fill again.
     * @throws ClosedException No more work will ever arrive.
     */
    public function tryReceive(): ?Work;

    /**
     * Wait up to $timeout for the next unit of work.
     *
     * Waiting suspends the fiber it was called from, not the thread: other fibers keep running and
     * the call resumes when work arrives. Called outside a fiber it blocks the process, since the
     * main context cannot be suspended.
     *
     * While the worker holds as many units as its pool allows, the call waits: backpressure, not an
     * error. Finalization verbs live on the returned unit and are plugin-specific.
     *
     * @param int<-1, max> $timeout Microseconds to wait; -1 waits indefinitely, 0 does not wait at
     *        all and throws {@see TimeoutException} at once when nothing is available — unlike
     *        {@see self::tryReceive()}, which returns null for that.
     * @throws TimeoutException No work became available within $timeout.
     * @throws ClosedException No more work will ever arrive. A shutdown throws it into a call that
     *         is already waiting.
     */
    public function receive(int $timeout = -1): Work;

    /**
     * Live plugin counters. Observability only — never a control-flow source.
     */
    public function info(): RuntimeInfo;
}
