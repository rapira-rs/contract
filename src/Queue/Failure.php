<?php

declare(strict_types=1);

namespace Rapira\Queue;

/**
 * Why a message failed, taken by {@see Delivery::fail()}.
 *
 * The host writes it to its log, and onto the message wherever it publishes the dead-letter itself.
 * A broker's own dead-letter path — AMQP's dead-letter exchange — has no place for it.
 */
final readonly class Failure
{
    /**
     * @param string $reason Developer-facing; what an operator reads first in the dead-letter.
     * @param array<non-empty-string, string> $details Structured facts a replay tool filters on — an
     *        error code, the field that failed validation.
     * @throws \ValueError A detail key is empty.
     */
    public function __construct(
        public string $reason = '',
        public array $details = [],
    ) {}
}
