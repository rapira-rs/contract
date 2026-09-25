<?php

declare(strict_types=1);

namespace Rapira\WebSocket;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Work;

/**
 * A connection ended: the last unit it produces, one per accepted connection, whoever ended it — the
 * client, a worker's {@see Connection::close()}, the host enforcing a limit or shutting down. The WHATWG
 * `error` and `close` events both land here, as the failed case of one ending.
 *
 * The place to undo what the connection set up — presence, room membership — and it comes even when no
 * closing handshake happened. It comes at most once, and not at all when the host process itself dies, so
 * state kept outside the host for a connection wants an expiry as well.
 *
 * The host never finalizes this unit itself: {@see self::isCancelled()} is always false, and
 * {@see self::complete()} never meets {@see \Rapira\Exception\WorkDiscardedException}.
 */
interface Close extends Work
{
    /** A handle whose sends are discarded, kept for {@see Connection::getId()}. */
    public function getConnection(): Connection;

    /** The connection's attachment: {@see Handshake::accept()}'s, as the last {@see Message::complete()} left it. */
    public function getAttachment(): string;

    /**
     * The status code of the first Close frame received from the client, whichever side started the closing
     * handshake (RFC 6455 §7.1.5, the WHATWG `CloseEvent.code`). `1005` when that frame carried none, `1006`
     * when no valid Close frame arrived — the client vanished, or sent a code or reason the protocol forbids
     * and the host failed the connection.
     *
     * @return int<1000, 4999>
     */
    public function getCode(): int;

    /** UTF-8, at most 123 bytes; empty when none was given. */
    public function getReason(): string;

    /** Whether the connection closed cleanly (RFC 6455 §7.1.4) — always false alongside `1006`. */
    public function wasClean(): bool;

    /**
     * Finalize the unit, letting the host forget the connection.
     *
     * @throws AlreadyFinalizedError The unit was already finalized.
     */
    public function complete(): void;
}
