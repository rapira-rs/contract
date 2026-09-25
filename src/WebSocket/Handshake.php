<?php

declare(strict_types=1);

namespace Rapira\WebSocket;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Http\Request;
use Rapira\Work;

/**
 * A client asking to open a WebSocket connection: the opening handshake, answered by {@see self::accept()}
 * or {@see self::reject()}.
 *
 * The host has already checked the protocol's own rules — the version, the key, the upgrade fields — and
 * answered a broken handshake itself, so what reaches the worker is the application's decision: who is
 * asking, from which page, for which subprotocol. The connection's handle exists before that decision, so
 * whatever the application registers for it happens inside this unit, before any other unit of the
 * connection can run.
 *
 * ```php
 * $headers = array_change_key_case($handshake->getRequest()->headers);
 * $origin = $headers['origin'][0] ?? null;
 * if (!$origins->allows($origin)) {
 *     $handshake->reject(403);
 *     return;
 * }
 * $offered = $handshake->getRequestedSubprotocols();
 * $user = $auth->fromCookies($headers['cookie'] ?? []);
 * $connection = $handshake->getConnection();
 * if ($user === null) {
 *     $connection->close(3000, 'sign in first');             // what a browser can read
 *     $handshake->accept($offered[0] ?? null);               // any offered one, or the browser fails first
 *     return;
 * }
 * $speaksChat = in_array('chat.v2', $offered, true);
 * $subprotocol = $speaksChat ? 'chat.v2' : null;
 * $attachment = json_encode(['v' => 1, 'user' => $user->id], JSON_THROW_ON_ERROR);
 * $presence->add($user->id, $connection->getId());
 * $connection->send('{"type":"welcome"}');                   // goes out right after the answer
 * $handshake->accept($subprotocol, attachment: $attachment);
 * ```
 *
 * The host never finalizes a handshake itself. A client that leaves before the answer turns
 * {@see self::isCancelled()} true, and the answer still succeeds: {@see self::accept()} is followed by the
 * connection's {@see Close}, `1006`, and {@see self::reject()} does nothing. A handshake rejected or
 * dropped unanswered — the host then answers `500` — has no {@see Close}: what it registered, it undoes.
 */
interface Handshake extends Work
{
    /**
     * The handshake as an HTTP request, the same shape the HTTP plugin hands out. $uri carries `http` or
     * `https`, never `ws` or `wss`. $body is empty. $method is `GET` over HTTP/1.1 and `CONNECT` over
     * HTTP/2 and HTTP/3 (RFC 8441, RFC 9220). There `upgrade` and `connection` are absent from $headers,
     * and `sec-websocket-key` and `host` usually are, so never branch on them. $target is the path either
     * way.
     */
    public function getRequest(): Request;

    /**
     * The subprotocols the client offered in `Sec-WebSocket-Protocol`, in its order of preference — the
     * same list {@see self::accept()} checks its choice against, byte for byte.
     *
     * @return list<non-empty-string> Empty when the client offered none.
     */
    public function getRequestedSubprotocols(): array;

    /**
     * The connection this handshake opens, before it is answered. Messages sent and a
     * {@see Connection::close()} made through it — or through {@see get_connection()} with its id — wait,
     * and go out right after {@see self::accept()}'s answer, ahead of anything sent under a later unit.
     * {@see self::reject()} discards them.
     */
    public function getConnection(): Connection;

    /**
     * Accept the handshake, opening the connection, and finalize this unit. The host answers `101` over
     * HTTP/1.1 and `200` over HTTP/2 and HTTP/3, then sends what {@see self::getConnection()} queued.
     *
     * The connection's {@see Message} units follow, then exactly one {@see Close}.
     *
     * @param non-empty-string|null $subprotocol One of {@see self::getRequestedSubprotocols()}, or null to
     *        pick none. A browser that offered any fails a connection accepted with none.
     * @param array<non-empty-string, list<string>> $headers Fields to add to the answer — `set-cookie` is the
     *        usual one. The framing and hop-by-hop fields — `content-length`, `transfer-encoding`,
     *        `connection`, `upgrade` and the rest — are dropped.
     * @param string $attachment Bytes the host keeps with the connection and returns on each of its units —
     *        {@see Message::getAttachment()}, {@see Close::getAttachment()} — whichever worker takes them.
     *        Never sent to the client and never logged. Its size limit is in `rapira.toml`.
     * @throws AlreadyFinalizedError The handshake was already answered.
     * @throws \ValueError $subprotocol was not offered; $headers carries a `sec-websocket-*` field, which is
     *         the host's to write, or a name or value not representable on the wire; $attachment is over the
     *         limit. Nothing is finalized then.
     */
    public function accept(?string $subprotocol = null, array $headers = [], string $attachment = ''): void;

    /**
     * Refuse the handshake with an HTTP response, and finalize this unit. What was queued on
     * {@see self::getConnection()} is discarded, and no {@see Close} follows.
     *
     * A browser shows the page neither the status nor the body — only an error and a close with `1006`.
     * To tell a page why, close the connection with a code and a reason, then accept.
     *
     * @param int<300, 599> $status An error, or a redirect, which a browser does not follow.
     * @param array<non-empty-string, list<string>> $headers
     * @throws AlreadyFinalizedError The handshake was already answered.
     * @throws \ValueError The status is outside `300`–`599`, or a header name or value is not representable
     *         on the wire.
     */
    public function reject(int $status = 403, array $headers = [], string $body = ''): void;
}
