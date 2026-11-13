<?php

declare(strict_types=1);

namespace Rapira\Queue\Kafka;

use Rapira\Queue\Delivery\Context;
use Rapira\Queue\Delivery\Message;
use Rapira\Queue\Delivery\Origin;

/**
 * A Kafka record's coordinates, on {@see Context::$origin}. The topic is {@see Message::$destination},
 * the record key {@see Message::$key}. Offsets are committed by the host, never by the worker: it
 * commits up to the lowest offset still unfinalized, so deliveries settle in any order.
 *
 * A retry the host republishes to a retry topic arrives with that topic's partition and offset, while
 * {@see Message::$destination} keeps the topic the producer wrote to: the offset identifies this
 * attempt, not the message.
 */
final readonly class KafkaOrigin implements Origin
{
    /**
     * @param int<0, max> $partition
     * @param int<0, max> $offset
     */
    public function __construct(
        public int $partition,
        public int $offset,
    ) {}
}
