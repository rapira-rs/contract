<?php

declare(strict_types=1);

namespace Rapira\Queue;

/**
 * The order a {@see PipelineInfo}'s messages settle in. The host enforces it at
 * {@see QueueDispatcher::receive()}: a message is not handed out while the one it must follow is held
 * unfinalized, and a retry keeps its place.
 *
 * The promise is one host's: it orders what it consumes, in the order the broker hands it over.
 * A second host consuming the same pipeline keeps it only where the broker gives each key or partition
 * to one consumer — a Kafka partition to one consumer group member, an SQS FIFO message group to one
 * receiver, a RabbitMQ queue, stream or super stream partition to its single active consumer. The host
 * refuses at boot an ordered pipeline on a Kafka share group or an SQS standard queue,
 * {@see self::PerKey} on a driver whose messages carry no key, and {@see self::PerPartition} on a
 * driver with no partitions.
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

    /**
     * In order per broker partition — a Kafka partition, a RabbitMQ super stream's partition or a
     * single stream — the order the broker itself keeps, with no key needed. The partition is on the
     * delivery's origin: {@see Kafka\KafkaOrigin::$partition}, {@see RabbitMq\RabbitMqStreamOrigin::$stream}.
     */
    case PerPartition = 'per-partition';

    /** In order across the whole pipeline: one delivery in flight at a time on this host. */
    case Total = 'total';
}
