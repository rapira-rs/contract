<?php

declare(strict_types=1);

namespace Rapira\Queue;

/**
 * The broker behind a {@see PipelineInfo}, backed by its `driver` name in `rapira.toml`. Each case
 * fixes which {@see Delivery\Context::$origin} the pipeline's deliveries carry.
 */
enum Driver: string
{
    /**
     * RabbitMQ queues — classic and quorum — over AMQP 0-9-1. A stream queue is refused at boot: it is
     * {@see self::RabbitMqStream}'s. Origin: {@see RabbitMq\RabbitMqOrigin}.
     */
    case RabbitMq = 'rabbitmq';

    /**
     * RabbitMQ streams and super streams over the stream protocol, read through a single active
     * consumer. Origin: {@see RabbitMq\RabbitMqStreamOrigin}.
     */
    case RabbitMqStream = 'rabbitmq-stream';

    /** A consumer group or a share group. Origin: {@see Kafka\KafkaOrigin}. */
    case Kafka = 'kafka';

    /** NATS core subscriptions, queue groups included: no persistence, no acknowledgement. Origin: {@see Nats\NatsOrigin}. */
    case Nats = 'nats';

    /** A NATS JetStream consumer. Origin: {@see Nats\JetStreamOrigin}. */
    case JetStream = 'jetstream';

    /** A Redis stream read through a consumer group. Origin: {@see Redis\RedisStreamOrigin}. */
    case RedisStream = 'redis-stream';

    /** A Redis list, moved to a processing list while held. No origin. */
    case RedisList = 'redis-list';

    /** Standard and FIFO queues. Origin: {@see Sqs\SqsOrigin}. */
    case Sqs = 'sqs';

    /** In the host process: lost on restart, no origin. */
    case Memory = 'memory';
}
