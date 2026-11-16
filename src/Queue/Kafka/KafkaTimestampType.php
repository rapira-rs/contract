<?php

declare(strict_types=1);

namespace Rapira\Queue\Kafka;

/**
 * Which time a Kafka record's timestamp is, on {@see KafkaOrigin::$timestampType}: the topic's
 * `message.timestamp.type` decides it, not the producer.
 */
enum KafkaTimestampType
{
    /** Set by the producer when it created the record — or by the producer's client, if it set none. */
    case CreateTime;

    /** Set by the broker when it appended the record to the log; whatever the producer set is replaced. */
    case LogAppendTime;
}
