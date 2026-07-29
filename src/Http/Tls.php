<?php

declare(strict_types=1);

namespace Rapira\Http;

/**
 * What the TLS handshake settled, for the connection this request arrived on. Present on
 * {@see Request::$tls} only when the listener terminated TLS itself — a plaintext listener behind a
 * terminating proxy has none of this, and the forwarding headers it sends instead are the framework's
 * business.
 *
 * The certificate fields describe the *client's* certificate and are all null unless one was presented,
 * which happens only where the listener asks for it. They are the identity mTLS authenticates on.
 */
final readonly class Tls
{
    /**
     * @param non-empty-string $version Protocol version as the TLS stack names it: `TLSv1.3`, `TLSv1.2`.
     * @param non-empty-string $cipher Negotiated cipher suite, likewise: `TLS_AES_256_GCM_SHA384`.
     * @param non-empty-string|null $alpn Protocol chosen by ALPN — `h2`, `http/1.1` — or null when the
     *        client offered no list. Not a substitute for {@see Request::$protocol}, which says what was
     *        actually spoken.
     * @param non-empty-string|null $sni Server name the client asked for in the handshake, or null if it
     *        sent none. Not the `Host` header: this one chose the certificate, before any request existed,
     *        so the two can disagree.
     * @param non-empty-string|null $certSerial Serial number of the client certificate, hex.
     * @param non-empty-string|null $certOrganization Organization named in the client certificate's
     *        subject.
     * @param non-empty-string|null $certFingerprint Digest of the client certificate, hex — the value to
     *        pin against, since a serial is only unique per issuer.
     */
    public function __construct(
        public string $version,
        public string $cipher,
        public ?string $alpn,
        public ?string $sni,
        public ?string $certSerial,
        public ?string $certOrganization,
        public ?string $certFingerprint,
    ) {}
}
