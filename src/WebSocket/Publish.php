<?php

declare(strict_types=1);

namespace Rapira\WebSocket;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Work;

/**
 * A client publishing to a private channel: a `publish` command on a connection accepted with the
 * `rapira.v1` subprotocol, for a channel the connection has joined and whose namespace leaves the decision
 * to PHP. Publishes to public channels are relayed by the host and never reach a worker.
 *
 * ```php
 * $text = $publish->getPayload();
 * $length = mb_strlen($text);
 * if ($length > 500) {
 *     $publish->deny(4013, 'too long');
 *     return;
 * }
 * $publish->allow($moderator->clean($text));
 * ```
 *
 * One unit of the connection's ordered chain: the host stops reading the client until it is finalized. The
 * publication goes out after anything already sent under this unit, to every subscriber, the publisher too.
 *
 * The host never finalizes it itself. Left unfinalized, the client gets error `500`; waiting past the queue
 * timeout, `503` — the connection stays open either way. {@see self::isCancelled()} turns true once the
 * connection is closing: answering still succeeds, and the reply is dropped.
 */
interface Publish extends Work
{
    public function getConnection(): Connection;

    /** The connection's attachment, as {@see Handshake::accept()} or the last {@see Message::complete()} left it. */
    public function getAttachment(): string;

    /** @return non-empty-string */
    public function getChannel(): string;

    /** The `data` the client sent, decoded from its JSON string: UTF-8 text. */
    public function getPayload(): string;

    /** Unix timestamp with microsecond precision, taken when the host received the whole command. */
    public function getReceivedAt(): float;

    /**
     * Publish to the channel, reply `ok`, and finalize this unit.
     *
     * @param string|null $data Replaces what is published; null publishes {@see self::getPayload()}.
     * @throws AlreadyFinalizedError The unit was already finalized.
     * @throws \ValueError $data is not valid UTF-8. Nothing is published or finalized then.
     */
    public function allow(?string $data = null): void;

    /**
     * Refuse, reply with an error, and finalize this unit. Nothing is published.
     *
     * @param int $code `403`, or `4000`–`4999` for a meaning of the application's own. The host's other
     *        codes — `400`, `404`, `429`, `500`, `503` — are never the application's.
     * @param string $reason UTF-8, at most 123 bytes.
     * @throws AlreadyFinalizedError The unit was already finalized.
     * @throws \ValueError The code is outside those ranges, or the reason is not UTF-8 or too long. Nothing
     *         is finalized then.
     */
    public function deny(int $code = 403, string $reason = ''): void;
}
