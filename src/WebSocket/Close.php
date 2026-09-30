<?php

declare(strict_types=1);

namespace Rapira\WebSocket;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Work;

/**
 * A connection ended: the last unit it produces, one per accepted connection, whoever ended it — the
 * client, a worker's {@see Connection::close()}, the host enforcing a limit or shutting down — including
 * one accepted after its client had already left. The WHATWG `error` and `close` events both land here, as
 * the failed case of one ending.
 *
 * The place to undo what the connection set up. It comes at most once, and not at all when the host
 * process itself dies.
 *
 * The host never finalizes this unit itself: {@see self::isCancelled()} is always false.
 */
interface Close extends Work
{
    /** A handle whose sends are discarded, kept for {@see Connection::getId()}. */
    public function getConnection(): Connection;

    /** The connection's attachment: {@see Handshake::accept()}'s, as the last {@see Message::complete()} left it. */
    public function getAttachment(): string;

    /**
     * The status code of the first Close frame received from the client, whichever side started the closing
     * handshake (RFC 6455 §7.1.5, the WHATWG `CloseEvent.code`). After {@see Connection::close()} it is the
     * client's answer — usually the same code, not necessarily. `1005` when that frame carried none; `1006`
     * when no valid Close frame arrived: the client vanished, the host failed the connection, or this side
     * closed and the client never answered.
     *
     * @return int<1000, 4999>
     */
    public function getCode(): int;

    /** UTF-8, at most 123 bytes; empty when none was given. */
    public function getReason(): string;

    /** Whether the connection closed cleanly (RFC 6455 §7.1.4) — always false alongside `1006`. */
    public function wasClean(): bool;

    /**
     * The code this side closed with, whether or not the frame got through: a worker's
     * {@see Connection::close()}, including one queued before the handshake was answered, or the host — over
     * a limit, on an idle connection (`1001`), past the queue timeout (`1013`), at shutdown, after a lost
     * {@see Message}, or failing the connection for a protocol error (`1002`, `1007`) — a malformed
     * `rapira.v1` frame is one. Null when this side only answered the client's
     * Close, or closed nothing.
     *
     * @return int<1000, 4999>|null
     */
    public function getSentCode(): ?int;

    /**
     * Finalize the unit, letting the host forget the connection.
     *
     * @throws AlreadyFinalizedError The unit was already finalized.
     */
    public function complete(): void;
}
