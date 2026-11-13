<?php

declare(strict_types=1);

namespace Rapira\Queue\Delivery;

/**
 * Message headers, on {@see Message::$headers}: multivalued, keys case-sensitive, values bytes.
 *
 * Brokers with typed values arrive as bytes by one rule: integers and decimals in decimal, floats in
 * the shortest form that reads back the same, booleans as `true` and `false`, AMQP timestamps as unix
 * seconds, a void or Kafka null value as the empty string, SQS `Binary` attributes as raw bytes. AMQP
 * arrays and nested tables have no bytes to become and are left out —
 * {@see \Rapira\Queue\Amqp\AmqpOrigin::$table} has them.
 */
final readonly class Headers implements \Countable, \IteratorAggregate
{
    /**
     * @param array<non-empty-string, non-empty-list<string>> $entries Keys as the producer spelled them;
     *        values in arrival order — Kafka, NATS and Redis streams repeat a key, the others give one
     *        value each.
     * @throws \ValueError A key is empty or maps to an empty list.
     * @throws \TypeError A key does not map to a list of strings.
     */
    public function __construct(
        public array $entries = [],
    ) {}

    /**
     * Every value of a key, in arrival order. Lookup is exact: `Trace-Id` and `trace-id` are two keys.
     *
     * @return list<string> Empty when the key is absent.
     */
    public function values(string $name): array {}

    /** @return int<0, max> Number of distinct keys. */
    public function count(): int {}

    /** @return \Iterator<non-empty-string, non-empty-list<string>> Over {@see self::$entries}. */
    public function getIterator(): \Iterator {}
}
