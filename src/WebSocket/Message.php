<?php

declare(strict_types=1);

namespace Rapira\WebSocket;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Work;

/**
 * One data message from a client: whole — the host reassembles fragments and undoes compression — and
 * already checked: within the size limit in `rapira.toml`, and valid UTF-8 when it is text, or the host
 * closed the connection with `1009` or `1007` instead.
 *
 * ```php
 * $state = json_decode($message->getAttachment(), true, flags: JSON_THROW_ON_ERROR);
 * $room = $state['room'] ?? null;
 * $members = $room === null ? [] : $rooms->members($room);
 * foreach ($members as $id) {
 *     \Rapira\WebSocket\get_connection($id)->send($message->getPayload(), $message->getType());
 * }
 * $message->complete();
 * ```
 *
 * The connection's next unit waits for {@see self::complete()}, and the host stops reading the client
 * meanwhile, without counting the pause against its ping and idle timeouts. Every message received before
 * the closing handshake starts is handed out, in order, before the connection's {@see Close}.
 *
 * The host never finalizes a message itself. {@see self::isCancelled()} turns true once the connection is
 * closing: sends to it are discarded from then on, and completing is still what the host waits for.
 */
interface Message extends Work
{
    public function getConnection(): Connection;

    /** What {@see Handshake::accept()} attached, as the last {@see self::complete()} replaced it. */
    public function getAttachment(): string;

    public function getType(): MessageType;

    public function getPayload(): string;

    /** Unix timestamp with microsecond precision, taken when the host received the whole message. */
    public function getReceivedAt(): float;

    /**
     * Finalize the message, releasing the connection's next unit.
     *
     * A lost message — the last reference dropped without this — is a failed handler: the host closes the
     * connection with `1011`.
     *
     * @param string|null $attachment Replaces the connection's attachment from its next unit on; null keeps
     *        it. No other unit of this connection runs until this returns.
     * @throws AlreadyFinalizedError The message was already finalized.
     * @throws \ValueError $attachment is over the limit in `rapira.toml`. Nothing is finalized then.
     */
    public function complete(?string $attachment = null): void;
}
