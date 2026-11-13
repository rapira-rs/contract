<?php

declare(strict_types=1);

namespace Rapira\Queue\Sqs;

use Rapira\Queue\Delivery\Context;
use Rapira\Queue\Delivery\Message;
use Rapira\Queue\Delivery\Origin;

/**
 * An SQS message's coordinates, on {@see Context::$origin}. `MessageId` is {@see Message::$id},
 * `MessageGroupId` {@see Message::$key}, `SentTimestamp` {@see Message::$publishedAt},
 * `ApproximateReceiveCount` {@see Context::$attempt}. The receipt handle stays with the host.
 */
final readonly class SqsOrigin implements Origin
{
    /**
     * @param non-empty-string|null $deduplicationId FIFO queues only.
     * @param non-empty-string|null $sequenceNumber FIFO queues only; a decimal string, since it runs
     *        past 64 bits.
     */
    public function __construct(
        public ?string $deduplicationId,
        public ?string $sequenceNumber,
    ) {}
}
