<?php

declare(strict_types=1);

namespace Rapira\Queue\Nats;

use Rapira\Queue\Delivery\Context;
use Rapira\Queue\Delivery\Message;
use Rapira\Queue\Delivery\Origin;

/**
 * A JetStream message's coordinates, on {@see Context::$origin}, from its delivery metadata. The subject
 * is {@see Message::$destination}; `num_delivered` is {@see Context::$attempt}.
 */
final readonly class JetStreamOrigin implements Origin
{
    /**
     * @param non-empty-string $stream
     * @param non-empty-string $consumer
     * @param int<1, max> $streamSequence The message's position in the stream — the same on every
     *        redelivery.
     * @param int<1, max> $consumerSequence This delivery's position in the consumer — new on each one.
     * @param int<0, max> $pending Messages left for the consumer when this one was delivered.
     */
    public function __construct(
        public string $stream,
        public string $consumer,
        public int $streamSequence,
        public int $consumerSequence,
        public int $pending,
    ) {}
}
