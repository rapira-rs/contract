<?php

declare(strict_types=1);

namespace Rapira\Http;

/**
 * A unix domain socket endpoint — the other arm of the address union, beside {@see InetAddress}.
 * No IP and no port exist here, and the type is what says so.
 */
final readonly class UnixAddress
{
    /**
     * @param non-empty-string|null $path Filesystem path of the socket. Null is an unnamed endpoint —
     *        the usual case for a connecting peer, which binds no path of its own.
     */
    public function __construct(
        public ?string $path,
    ) {}
}
