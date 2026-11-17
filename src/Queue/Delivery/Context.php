<?php

declare(strict_types=1);

namespace Rapira\Queue\Delivery;

use Rapira\Queue\Delivery;
use Rapira\Queue\Delivery\Origin;
use Rapira\Queue\QueueDispatcher;

/**
 * The context of one delivery, from {@see Delivery::getContext()}: what the broker and the host know
 * about this delivery of the message, whole and immutable. Delivery-scoped — the next attempt of the
 * same message comes with a context of its own.
 */
final readonly class Context
{
    /**
     * @param non-empty-string $pipeline The pipeline it was consumed through. Resolves against
     *        {@see QueueDispatcher::getPipelines()}.
     * @param int<1, max> $attempt 1 on the first attempt, never less than one plus the retries asked for
     *        this message. Where the host retries by republishing, its own count travels with the copy;
     *        where it retries in place — JetStream, SQS, Kafka share groups — it is the broker's
     *        delivery count, which also counts redeliveries after a lost lease. One gap: on an ordered
     *        pipeline over RabbitMQ classic queues or streams, Kafka consumer groups or Redis, the host
     *        holds a retry in place and counts it in memory, and a host restart starts over at 1; a quorum queue
     *        keeps the count itself, as `x-delivery-count`. The pipeline's maximum attempts is checked
     *        against this same number.
     * @param bool $redelivered An earlier attempt may have reached a worker and never been settled — the
     *        worker crashed or lost its lease — so its effects may already exist. Neither answer is
     *        proof. True may be a false alarm once a host restart has lost the host's record of the
     *        retries it asked for: on JetStream, SQS and Kafka share groups the broker counts a retry
     *        and a crash alike, and on an ordered pipeline a held retry comes back as a redelivery.
     *        False may miss one where the broker keeps no record — Kafka consumer groups and RabbitMQ
     *        streams re-read from the stored offset after a rebalance or a restart.
     * @param float $receivedAt Unix timestamp with microsecond precision, taken when the host fetched it
     *        from the broker — before it queued and before this worker took it.
     * @param float|null $deadline Unix timestamp with microsecond precision after which the host no
     *        longer accepts an outcome: the pipeline's processing limit, capped by how long the broker
     *        lets a lease be held — RabbitMQ's consumer timeout, SQS's twelve hours from receipt, a
     *        Kafka share group's acquisition lock where the broker cannot renew it. The
     *        lease is renewed up to it, {@see Delivery::isCancelled()} turns true when it passes, and
     *        the host retries the delivery as an unfinalized one. Null only when neither the pipeline
     *        nor the broker sets a limit: the lease is renewed for as long as the delivery is held.
     * @param Origin|null $origin
     *        The broker's own coordinates, typed per driver, for what no other field carries — a
     *        JetStream stream sequence, the same on every attempt, for an idempotent upsert; a
     *        correlation id. Null for drivers with nothing beyond
     *        the shared fields: Redis lists and the in-memory driver.
     */
    public function __construct(
        public string $pipeline,
        public int $attempt,
        public bool $redelivered,
        public float $receivedAt,
        public ?float $deadline,
        public ?Origin $origin,
    ) {}
}
