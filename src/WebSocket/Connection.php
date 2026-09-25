<?php

declare(strict_types=1);

namespace Rapira\WebSocket;

/**
 * The sending side of one WebSocket connection the host holds. Handed out by
 * {@see Handshake::getConnection()}, {@see Message::getConnection()}, {@see Close::getConnection()} and, from
 * any pool, by {@see get_connection()}.
 *
 * A handle, not a state: it carries no data and answers no liveness question. Sending to a connection that
 * has ended is not an error — the message is discarded, as the WHATWG `send()` discards on a closing
 * socket. The one place a connection's end is learned is its {@see Close} unit.
 *
 * Order follows the units. Everything sent while a worker holds a unit of connection X — to X or to any
 * other connection — goes out before anything sent under X's next unit, whichever worker holds that one,
 * so replies and relays keep the order their causes arrived in. Sends made outside a unit — after
 * {@see Message::complete()}, or from another pool — are ordered only among themselves, per worker:
 *
 * ```php
 * $connection->send('{"type":"ack"}');                   // before any reply to the next message
 * $message->complete();                                  // releases the next message now
 * $connection->send($slowService->answer($payload));     // unordered against later replies
 * ```
 */
interface Connection
{
    /**
     * The id that names this connection to {@see get_connection()}: at most 64 bytes of `A-Z`, `a-z`, `0-9`,
     * `_` and `-`, carrying at least 128 cryptographically random bits and no information. Never reused —
     * not after the connection ends, not across restarts, not across hosts — so a stored id can go dead but
     * never reaches somebody else's connection, and it is safe to hand to the client.
     *
     * @return non-empty-string
     */
    public function getId(): string;

    /**
     * Queue a message. Never waits: the host writes it, and a connection whose outbound buffer passes the
     * limit in `rapira.toml` is closed by the host with `1008` rather than holding the caller. Delivery is
     * never confirmed, and on a connection that is closing or closed the message is discarded.
     *
     * @throws \ValueError $type is {@see MessageType::Text} and $payload is not valid UTF-8 (RFC 6455 §5.6).
     */
    public function send(string $payload, MessageType $type = MessageType::Text): void;

    /**
     * Start the closing handshake (RFC 6455 §7.1.2). Messages sent before it still go out first; later ones
     * are discarded. The arguments are checked first; then, on a connection already closing or closed, it
     * does nothing, as the WHATWG `close()` does. The connection's {@see Close} unit follows once the
     * handshake completes or times out.
     *
     * @param int $code `1000`–`1003`, `1007`–`1009`, `1011`–`1014` or `3000`–`4999` (RFC 6455 §7.4, the
     *        IANA WebSocket Close Code Number Registry).
     * @param string $reason UTF-8, at most 123 bytes.
     * @throws \ValueError The code is not one a server may send, or the reason is not UTF-8 or too long.
     */
    public function close(int $code = 1000, string $reason = ''): void;
}
