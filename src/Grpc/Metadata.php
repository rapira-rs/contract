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
     *        not, is the boundary's job per the gRPC spec. A key made only of digits is an int key,
     *        as PHP arrays store it.
     * @throws \ValueError A key is empty, not lower-case, or not ASCII, or a value under a text key is
     *         not printable ASCII (0x20-0x7E; empty is allowed). `-bin` keys carry any bytes.
     * @throws \TypeError A key does not map to a list of strings.
     */
    public function __construct(
        public array $entries = [],
    ) {
        foreach ($entries as $key => $values) {
            $key = (string) $key;
            if (\preg_match('/^[^A-Z\x80-\xff]+$/D', $key) !== 1) {
                throw new \ValueError('a metadata key must be non-empty lower-case ASCII');
            }
            if (!\is_array($values) || !\array_is_list($values)) {
                throw new \TypeError("metadata key $key must map to a list of strings");
            }
            $binary = \str_ends_with($key, '-bin');
            foreach ($values as $value) {
                if (!\is_string($value)) {
                    throw new \TypeError("metadata key $key must map to a list of strings");
                }
                if (!$binary && \preg_match('/^[\x20-\x7e]*$/D', $value) !== 1) {
                    throw new \ValueError("a value of metadata key $key is not printable ASCII");
                }
            }
        }
    }

    /**
     * Every value of a key, in arrival order. Lookup is case-insensitive; a key with no values is an
     * empty list, indistinguishable from an absent one — gRPC metadata has no empty-vs-missing split.
     *
     * @return list<string> Raw bytes for a `-bin` key, printable ASCII otherwise.
     */
    public function values(string $name): array
    {
        return $this->entries[\strtolower($name)] ?? [];
    }

    /** @return int<0, max> Number of distinct keys. */
    public function count(): int
    {
        return \count($this->entries);
    }

    /** @return \Iterator<lowercase-string&non-empty-string|int, list<string>> Over {@see self::$entries}; a key made only of digits is an int. */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->entries);
    }
}
