<?php

declare(strict_types=1);

namespace Rapira\WebSocket;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Request;
use Rapira\Work;

/**
 * A client asking to open a WebSocket connection: the opening handshake, answered by {@see self::accept()}
 * or {@see self::reject()}. Nothing is a connection until it is accepted.
 *
 * The host has already checked the protocol's own rules — the version, the key, the upgrade fields — and
 * answered a broken handshake itself, so what reaches the worker is the application's decision: who is
 * asking, from which page, for which subprotocol.
 *
 * ```php
 * $request = $handshake->getRequest();
 * $headers = array_change_key_case($request->headers);
 * $user = $auth->fromCookies($headers['cookie'] ?? []);
 * if (!$origins->allows($headers['origin'][0] ?? null) || $user === null) {
 *     $handshake->reject(403);
 *     return;
 * }
 * $connection = $handshake->accept(
 *     subprotocol: in_array('chat.v2', $handshake->getRequestedSubprotocols(), true) ? 'chat.v2' : null,
 *     attachment: json_encode(['user' => $user->id, 'path' => parse_url($request->uri, PHP_URL_PATH)], JSON_THROW_ON_ERROR),
 * );
 * $connection->send('{"type":"welcome"}');
 * ```
 *
 * Browsers apply no same-origin policy to WebSockets: a page on any site can open one to this server, and
 * the browser sends this site's cookies with it. The host does not check `Origin`; with cookie
 * authentication, this is the one place to (RFC 6455 §10.2).
 *
 * {@see self::isCancelled()} turns true when the client leaves before an answer.
 */
interface Handshake extends Work
{
    /**
     * The handshake as an HTTP request, the same shape the HTTP plugin hands out, so one hydration path
     * serves both. $uri carries `http` or `https`, never `ws` or `wss` — the scheme of the request that
     * carried the handshake. $body is empty. $method is `GET` over HTTP/1.1 and `CONNECT` over HTTP/2 and
     * HTTP/3 (RFC 8441, RFC 9220), byte-for-byte as elsewhere: a router matching the endpoint as `GET`
     * should be shown `GET`. $target is the path either way — an extended `CONNECT` carries `:path`, unlike
     * the tunnel `CONNECT` whose target is an authority.
     */
    public function getRequest(): Request;

    /**
     * The subprotocols the client offered in `Sec-WebSocket-Protocol`, in its order of preference — the
     * same list {@see self::accept()} checks its choice against.
     *
     * @return list<non-empty-string> Empty when the client offered none.
     */
    public function getRequestedSubprotocols(): array;

    /**
     * Accept the handshake, opening the connection, and finalize this unit. The host answers `101` over
     * HTTP/1.1 and `200` over HTTP/2 and HTTP/3, so the worker never writes the status itself.
     *
     * The connection's {@see Message} units follow, then exactly one {@see Close}.
     *
     * @param non-empty-string|null $subprotocol One of {@see self::getRequestedSubprotocols()}, or null to
     *        pick none.
     * @param array<non-empty-string, list<string>> $headers Fields to add to the answer — `set-cookie` is the
     *        usual one. The handshake's own fields are the host's: `sec-websocket-*` is rejected, and the
     *        hop-by-hop fields are dropped, as from an HTTP head.
     * @param string $attachment Bytes the host keeps with the connection and returns on each of its units —
     *        {@see Message::getAttachment()}, {@see Close::getAttachment()} — whichever worker takes them.
     *        What the handshake decided (the user, the endpoint, the subprotocol) reaches later units this
     *        way. Its size limit is in `rapira.toml`.
     * @throws AlreadyFinalizedError The handshake was already answered.
     * @throws WorkDiscardedException The client left first. No connection exists, and no {@see Close}
     *         follows.
     * @throws \ValueError $subprotocol was not offered, or $headers carries a `sec-websocket-*` field or a
     *         name or value not representable on the wire, or $attachment is over the limit. Nothing is
     *         finalized then.
     */
    public function accept(?string $subprotocol = null, array $headers = [], string $attachment = ''): Connection;

    /**
     * Refuse the handshake with an HTTP response, and finalize this unit.
     *
     * @param int<300, 599> $status An error, or a redirect — which a browser does not follow: its WebSocket
     *        fails on any `3xx`. A `2xx` means accepted over HTTP/2 and HTTP/3, so it cannot spell a refusal.
     * @param array<non-empty-string, list<string>> $headers
     * @throws AlreadyFinalizedError The handshake was already answered.
     * @throws WorkDiscardedException The client left first.
     * @throws \ValueError The status is outside `300`–`599`, or a header name or value is not representable
     *         on the wire.
     */
    public function reject(int $status = 403, array $headers = [], string $body = ''): void;
}
