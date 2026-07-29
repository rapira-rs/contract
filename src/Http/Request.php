<?php

declare(strict_types=1);

namespace Rapira\Http;

/**
 * Request data as the host received it. Not a PSR-7 request and not trying to be one — a userland
 * adapter hydrates `ServerRequestInterface` from this, versioned against whichever
 * `psr/http-message` major the project uses.
 *
 * Carries only what PHP cannot derive: query parameters, cookies and content negotiation are parsed
 * from the fields below, not shipped alongside them.
 */
final readonly class Request
{
    /**
     * @param non-empty-string $method Uppercase, as received: `GET`, `POST`, and any extension method.
     * @param non-empty-string $uri Absolute form, synthesized by the host: scheme from the listener
     *        (only the host knows whether TLS terminated there), authority from the `Host` header.
     *        The routing and URL-generation surface.
     * @param non-empty-string $target Request-target (RFC 9112) byte-for-byte as it appeared on the
     *        request line: never parsed, never re-encoded. HTTP Message Signatures and SigV4 sign
     *        this exact string, so normalizing dot-segments or percent-encoding would break them. It
     *        is also the only honest representation of asterisk-form — `OPTIONS *` has `target = "*"`
     *        while $uri falls back to the authority root.
     * @param non-empty-string $protocol `HTTP/1.1`, `HTTP/2`, `HTTP/3`.
     * @param non-empty-string $remoteAddr Peer IP without the port. Deciding whether to trust it, and
     *        which forwarding header supersedes it, is the framework's business.
     * @param int<0, 65535> $remotePort Peer port. Zero for transports that have none.
     * @param array<non-empty-string, list<string>> $headers Names as received, one entry per value.
     *        Deliberately not normalized: casing is protocol-dependent (h2 and h3 lowercase field
     *        names by spec, h1 preserves whatever the client sent), and case-insensitive lookup is
     *        the consumer's job — PSR-7 requires it of implementations anyway.
     * @param string $body Whole body, buffered by the host up to its configured limit. Empty string
     *        when the request is bodiless.
     * @param float $receivedAt Unix timestamp with microsecond precision, taken when the host
     *        accepted the request — before it queued and before this worker took it. The only
     *        honest basis for time-to-first-byte and for a deadline the handler sets itself.
     */
    public function __construct(
        public string $method,
        public string $uri,
        public string $target,
        public string $protocol,
        public string $remoteAddr,
        public int $remotePort,
        public array $headers,
        public string $body,
        public float $receivedAt,
    ) {}
}
