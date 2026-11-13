<?php

declare(strict_types=1);

namespace Rapira\Queue\Amqp;

use Rapira\Queue\Delivery\Context;
use Rapira\Queue\Delivery\Message;
use Rapira\Queue\Delivery\Origin;

/**
 * An AMQP 0-9-1 delivery's coordinates, on {@see Context::$origin}: the basic properties the shared
 * fields do not carry. The routing key is {@see Message::$destination}, `message-id`
 * {@see Message::$id}, `timestamp` {@see Message::$publishedAt}. The delivery tag stays with the host —
 * it is valid only on the channel that delivered it.
 */
final readonly class AmqpOrigin implements Origin
{
    /**
     * @param string $exchange The exchange the producer published to, kept when the host republishes a
     *        retry, as {@see Message::$destination} is; empty for the default exchange.
     * @param int<0, 255>|null $priority
     * @param string|null $expiration The per-message TTL as the producer set it: milliseconds, as a
     *        string, which is how AMQP carries it.
     */
    public function __construct(
        public string $exchange,
        public ?string $correlationId,
        public ?string $replyTo,
        public ?string $type,
        public ?string $contentType,
        public ?string $contentEncoding,
        public ?string $appId,
        public ?string $userId,
        public ?int $priority,
        public ?string $expiration,
    ) {}
}
