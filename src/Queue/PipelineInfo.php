<?php

declare(strict_types=1);

namespace Rapira\Queue;

/**
 * One pipeline this pool consumes, from {@see QueueDispatcher::getPipelines()}: a named subscription on
 * one broker with its own policy, resolved from `rapira.toml` at boot. What an adapter needs to bind a
 * handler to it, and to refuse one that needs guarantees the pipeline does not give.
 */
final readonly class PipelineInfo
{
    /**
     * @param non-empty-string $name The name deliveries carry as {@see Delivery\Context::$pipeline}.
     * @param non-empty-list<non-empty-string> $sources What it consumes from, as configured: the queue
     *        on a RabbitMQ queue and SQS, streams or super streams on a RabbitMQ stream, topics on
     *        Kafka, subjects on NATS — patterns such as `orders.*` included — stream or list keys on
     *        Redis. Except on a RabbitMQ queue, {@see Delivery\Message::$destination} is always one of
     *        them or a name one of them matched.
     */
    public function __construct(
        public string $name,
        public Driver $driver,
        public array $sources,
        public Ordering $ordering,
        public Guarantee $guarantee,
    ) {}
}
