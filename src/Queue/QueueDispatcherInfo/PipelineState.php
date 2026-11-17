<?php

declare(strict_types=1);

namespace Rapira\Queue\QueueDispatcherInfo;

/**
 * Whether the host is consuming a pipeline, on {@see PipelineCounters::$state}. Observability only:
 * the host restores what it can on its own, and a worker never acts on it.
 */
enum PipelineState
{
    /**
     * Subscribed, and the broker may hand messages over. Also where the host cannot tell idle from
     * consuming: a standby single active consumer on a RabbitMQ queue looks the same over AMQP 0-9-1.
     */
    case Consuming;

    /**
     * Subscribed, but the broker assigns this host nothing until the group changes: a Kafka consumer
     * group member with no partitions, a paused JetStream consumer, a standby single active consumer on
     * a RabbitMQ stream.
     */
    case Idle;

    /** The connection or the subscription is lost — a broker cancel, a closed channel — and the host is restoring it. */
    case Reconnecting;

    /**
     * The broker refused in a way the host does not retry: the queue, stream or consumer group is
     * gone, or access is denied.
     */
    case Stopped;
}
