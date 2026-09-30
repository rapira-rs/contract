<?php

declare(strict_types=1);

namespace Rapira\WebSocket;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Work;

/**
 * A client asking to join a private channel: a `subscribe` command on a connection accepted with the
 * `rapira.v1` subprotocol, for a channel whose namespace leaves the decision to PHP. Public channels,
 * user-limited ones and refusals the host makes itself never reach a worker.
 *
 * ```php
 * $state = json_decode($subscribe->getAttachment(), true, flags: JSON_THROW_ON_ERROR);
 * $isMember = $rooms->isMember($subscribe->getChannel(), $state['user']);
 * if (!$isMember) {
 *     $subscribe->deny(4004, 'not a member');
 *     return;
 * }
 * $subscribe->allow();
 * ```
 *
 * One unit of the connection's ordered chain: the host stops reading the client until it is finalized,
 * and the `ok` reply goes out before the channel's first publication.
 *
 * The host never finalizes it itself. Left unfinalized, the client gets error `500`; waiting past the queue
 * timeout, `503` — the connection stays open either way. {@see self::isCancelled()} turns true once the
 * connection is closing: answering still succeeds, and the reply is dropped.
 */
interface Subscribe extends Work
{
    public function getConnection(): Connection;

    /** The connection's attachment, as {@see Handshake::accept()} or the last {@see Message::complete()} left it. */
    public function getAttachment(): string;

    /** @return non-empty-string */
    public function getChannel(): string;

    /**
     * Join the connection to the channel, reply `ok`, and finalize this unit.
     *
     * @throws AlreadyFinalizedError The unit was already finalized.
     */
    public function allow(): void;

    /**
     * Refuse, reply with an error, and finalize this unit.
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
