<?php

declare(strict_types=1);

namespace Rapira\Queue\RabbitMq;

use Rapira\Queue\Delivery\Context;
use Rapira\Queue\Delivery\Message;
use Rapira\Queue\Delivery\Origin;

/**
 * A RabbitMQ stream message's coordinates, on {@see Context::$origin}: its position in the log and the
 * AMQP 1.0 properties the shared fields do not carry. The stream or super stream the producer wrote to
 * is {@see Message::$destination}, `message-id` {@see Message::$id}, the chunk's timestamp
 * {@see Message::$publishedAt}; application properties are {@see Message::$headers}. Offsets are stored
 * by the host, never by the worker: it stores the lowest offset still unfinalized, so deliveries settle
 * in any order.
 *
 * A retry the host republishes to a retry stream arrives with that stream's position, while
 * {@see Message::$destination} keeps the stream the producer wrote to: the offset identifies this
 * attempt, not the message.
 */
final readonly class RabbitMqStreamOrigin implements Origin
{
    /**
     * @param non-empty-string $stream The stream read: a super stream's partition, or the stream itself.
     * @param int<0, max> $offset
     */
    public function __construct(
        public string $stream,
        public int $offset,
        public ?string $correlationId,
        public ?string $replyTo,
        public ?string $subject,
        public ?string $contentType,
        public ?string $contentEncoding,
    ) {}
}
