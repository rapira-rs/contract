<?php

declare(strict_types=1);

namespace Rapira\Grpc;

/**
 * Metadata as gRPC defines it: multivalued, keys case-insensitive ASCII, `-bin` keys carrying
 * binary. A flat string map would silently lose duplicates and leave binary values ambiguous,
 * which is why $entries is not `array<string, string>`. Both sides hand it out: the request's on
 * {@see Call\Context::$metadata}, the response halves as {@see Responder\ResponseMetadata}
 * snapshots.
 */
final readonly class Metadata implements \Countable, \IteratorAggregate
{
    /**
     * @param array<lowercase-string&non-empty-string, list<string>> $entries Keys already normalized
     *        to lower case; values of `-bin` keys already decoded to raw bytes — base64, padded or
     *        not, is the boundary's job per the gRPC spec.
     * @throws \ValueError A key is empty, not lower-case, or not ASCII — or a value under a text key
     *         is not ASCII; `-bin` keys carry any bytes.
     */
    public function __construct(
        public array $entries = [],
    ) {}

    /**
     * Every value of a key, in arrival order. Lookup is case-insensitive; a key with no values is an
     * empty list, indistinguishable from an absent one — gRPC metadata has no empty-vs-missing split.
     *
     * @return list<string> Raw bytes for a `-bin` key, ASCII otherwise.
     */
    public function values(string $name): array {}

    /** @return int<0, max> Number of distinct keys. */
    public function count(): int {}

    /** @return \Iterator<lowercase-string&non-empty-string, list<string>> Over {@see self::$entries}. */
    public function getIterator(): \Iterator {}
}
