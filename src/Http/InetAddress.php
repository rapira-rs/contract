<?php

declare(strict_types=1);

namespace Rapira\Http;

/**
 * An IP endpoint of the connection — one arm of the address union on {@see Request::$remote} and
 * {@see Request::$server}; the other is {@see UnixAddress}. The union is why there is no zero
 * sentinel: a port exists exactly when the endpoint is an IP one.
 */
final readonly class InetAddress
{
    /**
     * @param non-empty-string $ip Without the port. v4 or v6, as the socket reports it.
     * @param int<1, 65535> $port
     */
    public function __construct(
        public string $ip,
        public int $port,
    ) {}
}
