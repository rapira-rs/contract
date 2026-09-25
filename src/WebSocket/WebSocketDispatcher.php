<?php

declare(strict_types=1);

namespace Rapira\WebSocket;

use Rapira\Dispatcher;

/**
 * The WebSocket plugin's dispatcher. Obtain it from {@see \Rapira\get_dispatcher()} when the worker serves
 * the `websocket` section of `rapira.toml`.
 *
 * The host terminates the protocol and holds every connection; the worker never holds one. What it takes
 * from here are short units — a connection asking to open, a message that arrived, a connection that
 * ended — each finalized before the worker moves on, so no worker is pinned by a connection that stays open
 * for hours. One worker script can serve both pools, telling them apart by {@see Dispatcher::name()}:
 *
 * ```php
 * $dispatcher = \Rapira\get_dispatcher();
 * if ($dispatcher->name() !== 'websocket') {
 *     serveHttp($dispatcher);
 *     return;
 * }
 *
 * try {
 *     while (true) {
 *         $unit = $dispatcher->receive();
 *         if ($unit instanceof Handshake) {
 *             $gate->answer($unit);                            // Origin, auth: accept() or reject()
 *             continue;
 *         }
 *         if ($unit instanceof Message) {
 *             $chat->relay($unit);
 *         } else {
 *             $presence->leave($unit->getAttachment());
 *         }
 *         $unit->complete();
 *     }
 * } catch (\Rapira\Exception\ClosedException) {
 *     // drained
 * }
 * ```
 *
 * The units of one connection come in order, and the next one is handed out only after the previous one
 * is finalized — to any worker of the pool — so a connection is processed sequentially while different
 * connections are not tied to each other.
 */
interface WebSocketDispatcher extends Dispatcher
{
    /**
     * Take a unit if one is available right now. Never blocks.
     *
     * @return Handshake|Message|Close|null Null means nothing is available at this moment; the queue may
     *         fill again.
     * @throws \Rapira\Exception\ClosedException No more units will ever arrive.
     */
    public function tryReceive(): Handshake|Message|Close|null;

    /**
     * Wait up to $timeout for the next unit, with {@see Dispatcher::receive()}'s waiting semantics: the
     * fiber suspends, not the thread; outside a fiber the process blocks.
     *
     * On shutdown the host stops taking handshakes, closes every connection — `1001`, or `1012` when it is
     * restarting — and hands out their {@see Close} units before this throws {@see
     * \Rapira\Exception\ClosedException}. A worker leaving the pool closes nothing — connections are the
     * host's, not the worker's — except the one whose {@see Message} it held unfinalized, which the host
     * closes with `1011` as for any lost message.
     *
     * @param int<-1, max> $timeout Microseconds to wait; -1 waits indefinitely, 0 does not wait at all.
     * @throws \Rapira\Exception\TimeoutException No unit became available within $timeout.
     * @throws \Rapira\Exception\ClosedException No more units will ever arrive.
     */
    public function receive(int $timeout = -1): Handshake|Message|Close;

    /**
     * Live plugin counters. Observability only — never a control-flow source.
     */
    public function getInfo(): WebSocketDispatcherInfo;
}
