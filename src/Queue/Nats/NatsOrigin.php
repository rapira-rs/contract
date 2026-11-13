<?php

declare(strict_types=1);

namespace Rapira\Queue\Nats;

use Rapira\Queue\Delivery\Context;
use Rapira\Queue\Delivery\Message;
use Rapira\Queue\Delivery\Origin;

/**
 * A NATS core message's coordinates, on {@see Context::$origin}. The subject is
 * {@see Message::$destination}.
 */
final readonly class NatsOrigin implements Origin
{
    /**
     * @param non-empty-string|null $replyTo The subject a requester waits on. Answering it is
     *        publishing, which this contract does not do; null for a plain publish.
     */
    public function __construct(
        public ?string $replyTo,
    ) {}
}
