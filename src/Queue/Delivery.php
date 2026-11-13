<?php

declare(strict_types=1);

namespace Rapira\Queue;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Queue\Delivery\Context;
use Rapira\Queue\Delivery\Message;
use Rapira\Work;

/**
 * One delivery of one message to this worker: what the producer sent, what the broker and the host
 * know about this delivery of it, and the three verbs that settle it.
 *
 * The verbs speak the queue's language, never a broker's: the host translates each one per driver —
 * natively where the broker has the operation, by republishing where it does not.
 *
 * While the delivery is unfinalized the host keeps the broker's lease alive — ack wait, visibility
 * timeout, acquisition lock — up to {@see Context::$deadline}. A lease lost anyway — a Kafka rebalance
 * revoking the partition, a renewal the broker refused — turns {@see self::isCancelled()} true, and the
 * broker redelivers the message on its own.
 *
 * Left unfinalized — dropped, past {@see Context::$deadline}, or held when the worker drains — the
 * delivery is retried per its pipeline's policy, as {@see self::retry()} with no delay would do, and the
 * attempt counts, so a message that keeps crashing its worker exhausts its attempts and fails. On AMQP
 * classic queues, Kafka consumer groups and Redis that count is the host's, and a crash that takes the host down with
 * it hands the message back at the same attempt.
 */
interface Delivery extends Work
{
    /**
     * What the producer sent, unchanged across redeliveries and retries.
     */
    public function getMessage(): Message;

    /**
     * This delivery of the message: pipeline, attempt, deadline, the broker's own coordinates.
     */
    public function getContext(): Context;

    /**
     * Done: acknowledge, commit, delete — whatever the driver calls it. On an
     * {@see Guarantee::AtMostOnce} pipeline there is nothing to acknowledge, and the call only
     * finalizes the unit.
     *
     * @throws AlreadyFinalizedError The delivery was already finalized.
     * @throws WorkDiscardedException The host closed the delivery first.
     */
    public function complete(): void;

    /**
     * Back for another attempt, with {@see Context::$attempt} one higher. Past the pipeline's maximum
     * attempts the message fails instead, as {@see self::fail()} would.
     *
     * On a pipeline ordered {@see Ordering::PerKey} or {@see Ordering::Total}, the retry keeps its place:
     * the messages behind it wait until it is settled. There the host never republishes the retry: it
     * holds the original unacknowledged through the delay, so a host restart hands it back in its
     * place, and the delay is cut to what the broker lets the host hold a message — RabbitMQ's consumer
     * timeout, SQS's twelve hours from receipt, a Kafka share group's acquisition lock where the broker
     * cannot renew it. On an {@see Guarantee::AtMostOnce} pipeline the host holds the retry in
     * memory, and a restart loses it.
     *
     * @param int<0, max>|null $delay Microseconds until the next attempt; null takes the pipeline's
     *        backoff. Any delay on any driver — where the broker has no delayed redelivery, or caps it,
     *        the host schedules it — except for the cut on an ordered pipeline.
     * @throws AlreadyFinalizedError The delivery was already finalized.
     * @throws WorkDiscardedException The host closed the delivery first.
     * @throws \ValueError $delay is negative.
     */
    public function retry(?int $delay = null): void;

    /**
     * Final: the message will not be attempted again. Where it goes — AMQP's dead-letter exchange, a
     * dead-letter pipeline the host publishes it to, nowhere — is the pipeline's policy.
     *
     * @throws AlreadyFinalizedError The delivery was already finalized.
     * @throws WorkDiscardedException The host closed the delivery first.
     */
    public function fail(Failure $failure = new Failure()): void;
}
