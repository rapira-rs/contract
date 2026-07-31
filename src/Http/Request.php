<?php

declare(strict_types=1);

namespace Rapira\Http;

/**
 * Request data as the host received it. Not a PSR-7 request: a userland adapter hydrates
 * `ServerRequestInterface` from this, against whichever `psr/http-message` major the project uses.
 *
 * Query parameters, cookies and content negotiation are not here — they are parsed from the fields below.
 */
final readonly class Request
{
    /**
     * @param non-empty-string $method Byte-for-byte as received, never normalized: methods are
     *        case-sensitive tokens (RFC 9110 §9.1), so a lowercase extension method stays lowercase and
     *        is not the uppercase one. Every standard method is uppercase on the wire.
     * @param non-empty-string $uri Absolute form, synthesized by the host: scheme from the listener,
     *        authority from $authority, falling back to the listener address when the client named
     *        none. The routing and URL-generation surface.
     * @param non-empty-string $target Request-target byte-for-byte, never parsed and never re-encoded —
     *        HTTP Message Signatures and SigV4 sign this exact string. On HTTP/1.1 it is the
     *        request-target as it appeared on the request line (RFC 9112); on h2 and h3, which have no
     *        request line, the `:path` pseudo-header — and the authority for `CONNECT`, which travels
     *        without one. Also the only honest representation of asterisk-form: `OPTIONS *` has
     *        `target = "*"` while $uri falls back to the authority root.
     * @param non-empty-string|null $authority The authority the client named, byte-for-byte, whichever
     *        slot carried it: `:authority` on h2 and h3 — the `Host` header when only that was sent —
     *        and the `Host` header on HTTP/1.1. Null when the request named none, which HTTP/1.0 alone
     *        allows: an HTTP/1.1 request without `Host` is answered `400` by the host (RFC 9112 §3.2)
     *        and never dispatched.
     * @param non-empty-string $protocol `HTTP/1.1`, `HTTP/2`, `HTTP/3`.
     * @param array<non-empty-string, list<string>> $headers Names as received, one entry per value, not
     *        normalized: casing is protocol-dependent — h2 and h3 lowercase field names by spec, h1
     *        preserves whatever the client sent — so case-insensitive lookup is the consumer's job.
     *        Pseudo-headers are not fields (RFC 9113 §8.3) and never appear here — their facts arrive
     *        as $method, $target and $authority. `Host` is a field and stays exactly as received, which
     *        on h2 usually means absent; nothing is synthesized into this array.
     * @param string|Multipart $body The payload, in exactly one spelling — the union is what enforces
     *        it. A body is read by its framing, never by the method name: a `QUERY` body arrives the
     *        same way a `POST` one does. A string is the bytes as received, transfer encoding undone
     *        and nothing else touched:
     *        no form parsing, no JSON decoding — empty when the request carried none, and whole,
     *        because the host collects it before dispatching. A {@see Multipart} is a
     *        `multipart/form-data` body the host parsed instead; only that type is parsed —
     *        `multipart/mixed` and the rest stay raw. Limits live in `rapira.toml`: past them the host
     *        answers `413`, and a malformed multipart body — bad framing, a duplicated
     *        `content-disposition` or parameter — is answered `400` and never dispatched, so no two
     *        parsers in the chain can disagree about it.
     * @param non-empty-string $remoteAddr Peer IP without the port. Deciding whether to trust it, and
     *        which forwarding header supersedes it, is the framework's business.
     * @param int<0, 65535> $remotePort Peer port. Zero for transports that have none.
     * @param non-empty-string $serverAddr IP of the listener that accepted the connection, and
     *        $serverPort its port: which socket took the call, not the `Host` header the client wrote.
     *        A deployment with an internal and an external listener tells them apart by this.
     * @param int<0, 65535> $serverPort
     * @param Tls|null $tls What the handshake settled, or null on a plaintext listener.
     * @param float $receivedAt Unix timestamp with microsecond precision, taken when the host accepted
     *        the request — before it queued and before this worker took it. It dates this request, not
     *        the connection, which on keep-alive carried many others before it.
     */
    public function __construct(
        public string $method,
        public string $uri,
        public string $target,
        public ?string $authority,
        public string $protocol,
        public array $headers,
        public string|Multipart $body,
        public string $remoteAddr,
        public int $remotePort,
        public string $serverAddr,
        public int $serverPort,
        public ?Tls $tls,
        public float $receivedAt,
    ) {}
}
