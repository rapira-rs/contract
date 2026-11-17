<?php

declare(strict_types=1);

namespace Rapira\Queue;

/**
 * What a {@see PipelineInfo} promises when a worker or the host dies mid-delivery.
 */
enum Guarantee: string
{
    /** Lost, never repeated: NATS core, a Redis list without a processing list, the in-memory driver. The verbs still finalize; there is nothing to acknowledge. */
    case AtMostOnce = 'at-most-once';

    /** Repeated, never lost to a crash: a handler that is not idempotent checks {@see Delivery\Context::$redelivered}. */
    case AtLeastOnce = 'at-least-once';
}
