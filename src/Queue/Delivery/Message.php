<?php

declare(strict_types=1);

namespace Rapira\Queue\Delivery;

use Rapira\Queue\Delivery;

/**
 * The message of a delivery, from {@see Delivery::getMessage()}: what the producer sent, whole and
 * immutable. A retry the host republishes — to a delay queue, a retry topic — carries every field
 * forward, so the same message looks the same on every attempt; what changes between attempts lives
 * on {@see Context}.
 */
final readonly class Message
{
    /**
     * @param non-empty-string|null $id The message's own identity, stable across redeliveries and
     *        retries — the idempotency key when there is one. The `message-id` property on RabbitMQ
     *        queues and streams, JetStream's `Nats-Msg-Id`, SQS's `MessageId`. Null when the message
     *        carries none: always on Kafka, NATS core and Redis, and on RabbitMQ and JetStream whenever
     *        the producer set none.
     * @param string $destination Where the producer sent it: the topic on Kafka, the stream or super
     *        stream on a RabbitMQ stream, the subject on NATS and JetStream, the key on Redis, the
     *        queue name on SQS — on a pipeline subscribed by
     *        pattern, the concrete name that matched. On AMQP the routing key as published, which a
     *        fanout exchange ignores: the producer addresses an exchange, and the queue the pipeline
     *        consumes is whatever its bindings matched.
     * @param string|null $key The ordering and partitioning key: the record key on Kafka,
     *        `MessageGroupId` on SQS. What {@see \Rapira\Queue\Ordering::PerKey} orders by — an SQS
     *        standard queue carries a group id without ordering by it. Null where the producer named
     *        none or the driver has no such notion.
     * @param string|null $body Bytes as the producer sent them; decoding is the producer's agreement
     *        with the handler. On a Redis stream, the entry field the pipeline names as its body. Null
     *        when there is none — a Kafka tombstone, a stream entry without that field — which is
     *        not the same as an empty body.
     * @param Headers $headers Application headers, as the broker carries them. Facts promoted to fields
     *        here stay where the producer put them — `Nats-Msg-Id` is both $id and a header; the host's
     *        own retry bookkeeping never appears.
     * @param float|null $publishedAt Unix timestamp with microsecond precision: when the producer sent it
     *        or the broker stored it, whichever the broker records — the record's timestamp on Kafka per
     *        the topic's `message.timestamp.type`, the chunk's timestamp on a RabbitMQ stream, the
     *        stored time on JetStream and SQS, the entry id's time on a Redis stream, the `timestamp`
     *        property, in whole seconds, on a RabbitMQ queue. Null when the broker records none.
     */
    public function __construct(
        public ?string $id,
        public string $destination,
        public ?string $key,
        public ?string $body,
        public Headers $headers,
        public ?float $publishedAt,
    ) {}
}
