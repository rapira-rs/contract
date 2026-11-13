<?php

declare(strict_types=1);

namespace Rapira\Queue;

/**
 * The order a {@see PipelineInfo}'s messages settle in. The host enforces it at
 * {@see QueueDispatcher::receive()}: a message is not handed out while the one it must follow is held
 * unfinalized, and a retry keeps its place.
 *
 * The promise is one host's: it orders what it consumes, in the order the broker hands it over.
 * A second host consuming the same pipeline keeps it only where the broker gives each key to one
 * consumer — a Kafka partition to one consumer group member, an SQS FIFO message group to one
 * receiver, an AMQP queue to its single active consumer. The host refuses at boot an ordered pipeline
 * on a Kafka share group or an SQS standard queue, and {@see self::PerKey} on a driver whose messages
 * carry no key.
 */
enum Ordering: string
{
    /** No promise. Deliveries run in parallel, and the host holds nothing back behind a retry. */
    case Unordered = 'unordered';

    /**
     * In order per {@see Delivery\Message::$key}. A message with a null key follows nothing and holds
     * nothing back.
     */
    case PerKey = 'per-key';

    /** In order across the whole pipeline: one delivery in flight at a time on this host. */
    case Total = 'total';
}
