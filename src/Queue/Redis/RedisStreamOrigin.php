<?php

declare(strict_types=1);

namespace Rapira\Queue\Redis;

use Rapira\Queue\Delivery\Context;
use Rapira\Queue\Delivery\Message;
use Rapira\Queue\Delivery\Origin;

/**
 * A Redis stream entry's coordinates, on {@see Context::$origin}. The stream key is
 * {@see Message::$destination}; the entry's fields are {@see Message::$body} and
 * {@see Message::$headers}.
 */
final readonly class RedisStreamOrigin implements Origin
{
    /**
     * @param non-empty-string $id The entry id, `1526919030474-55`: the same when the entry is claimed
     *        by another consumer, new when the host republishes a retry.
     */
    public function __construct(
        public string $id,
    ) {}
}
