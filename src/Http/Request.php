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
     * @param non-empty-string $method Uppercase, as received: `GET`, `POST`, and any extension method.
     * @param non-empty-string $uri Absolute form, synthesized by the host: scheme from the listener,
     *        authority from the `Host` header. The routing and URL-generation surface.
     * @param non-empty-string $target Request-target (RFC 9112) byte-for-byte as it appeared on the
     *        request line, never parsed and never re-encoded — HTTP Message Signatures and SigV4 sign
     *        this exact string. Also the only honest representation of asterisk-form: `OPTIONS *` has
     *        `target = "*"` while $uri falls back to the authority root.
     * @param non-empty-string $protocol `HTTP/1.1`, `HTTP/2`, `HTTP/3`.
     * @param array<non-empty-string, list<string>> $headers Names as received, one entry per value, not
     *        normalized: casing is protocol-dependent — h2 and h3 lowercase field names by spec, h1
     *        preserves whatever the client sent — so case-insensitive lookup is the consumer's job.
     * @param string $body The payload as received, with any transfer encoding undone and nothing else
     *        touched: no form parsing, no JSON decoding. Empty when the request carried none, and whole,
     *        because the host collects it before dispatching. Past the configured limit the host answers
     *        `413` and this object never exists.
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
        public string $protocol,
        public array $headers,
        public string $body,
        public string $remoteAddr,
        public int $remotePort,
        public string $serverAddr,
        public int $serverPort,
        public ?Tls $tls,
        public float $receivedAt,
    ) {}
}
