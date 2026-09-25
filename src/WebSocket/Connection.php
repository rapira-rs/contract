<?php

declare(strict_types=1);

namespace Rapira\WebSocket;

/**
 * The sending side of one WebSocket connection the host holds. Handed out by {@see Handshake::accept()},
 * {@see Message::getConnection()}, {@see Close::getConnection()} and, from any pool, by
 * {@see get_connection()}.
 *
 * A handle, not a state: it carries no data and answers no liveness question. Sending to a connection that
 * has ended is not an error — the message is discarded, as the WHATWG `send()` discards on a closing
 * socket — because whether it is still open would be stale by the time the frame reached the wire. The
 * one place a connection's end is learned is its {@see Close} unit.
 *
 * ```php
 * $connection->send('{"type":"welcome"}');
 * $connection->send($message->getPayload(), $message->getType());   // relay as received
 * $connection->close(4001, 'session expired');
 * ```
 */
interface Connection
{
    /**
     * The id that names this connection to {@see get_connection()}: opaque, assigned by the host, and never
     * reused — not after the connection ends, not across restarts — so a stored id can go dead but never
     * reach somebody else's connection.
     *
     * @return non-empty-string
     */
    public function getId(): string;

    /**
     * Queue a message. Never waits: the host writes it, and a connection whose outbound buffer passes the
     * limit in `rapira.toml` is closed by the host with `1008` rather than holding the caller.
     *
     * Messages one worker sends to one connection reach it in the order of the calls, fibers included;
     * nothing orders the sends of different workers. Delivery is never confirmed, and on a connection that
     * is closing or closed the message is discarded.
     *
     * @throws \ValueError $type is {@see MessageType::Text} and $payload is not valid UTF-8 (RFC 6455 §5.6).
     */
    public function send(string $payload, MessageType $type = MessageType::Text): void;

    /**
     * Start the closing handshake (RFC 6455 §7.1.2). Messages sent before it still go out first; later
     * ones are discarded. On a connection already closing or closed it does nothing, as the WHATWG
     * `close()` does. The connection's {@see Close} unit follows once the handshake completes or times out.
     *
     * @param int $code `1000`–`1003`, `1007`–`1014` or `3000`–`4999`. Unlike a browser, a server may send the
     *        protocol codes — `1001` going away, `1008` policy violation, `1011` internal error. `1005`,
     *        `1006` and `1015` never travel on the wire (RFC 6455 §7.4.1), `1004` is reserved, and so is the
     *        rest of `1000`–`2999` until registered.
     * @param string $reason UTF-8, at most 123 bytes — what fits a control frame beside the code.
     * @throws \ValueError The code is not one a server may send, or the reason is not UTF-8 or too long.
     */
    public function close(int $code = 1000, string $reason = ''): void;
}
