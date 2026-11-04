<?php

declare(strict_types=1);

namespace Rapira\Queue\QueueDispatcherInfo;

use Rapira\Queue\QueueDispatcherInfo;

/**
 * One pipeline's counters, from {@see QueueDispatcherInfo::byPipeline()}. Each counts a different
 * place a message can wait: in the broker, in the host, in a worker.
 */
final readonly class PipelineCounters
{
    /**
     * @param int<0, max>|null $backlog Still in the broker, not yet fetched by the host — so never
     *        counting what $pending or $active hold: the queue's ready count on AMQP, the distance from
     *        the host's fetch position to the log end on Kafka, `num_pending` on JetStream, the
     *        consumer group's `lag` on a Redis stream, the length of a Redis list, the approximate
     *        visible count on SQS. As last reported by the broker, which reports on its own schedule.
     *        Always 0 on NATS core, which delivers or drops, and on the in-memory driver, whose
     *        waiting messages are all $pending. Null when the broker cannot give the number right
     *        now: no report yet since boot, the broker unreachable, a Redis stream's lag unknown
     *        after deletions.
     * @param int<0, max> $pending Fetched and held by the host, not yet handed to any worker — the
     *        prefetch buffer. A message withheld behind its predecessor on an ordered pipeline counts
     *        here; a retry the host holds for its delay counts once the delay has passed.
     * @param int<0, max> $active Handed to this worker and not yet finalized.
     */
    public function __construct(
        public ?int $backlog,
        public int $pending,
        public int $active,
    ) {}
}
