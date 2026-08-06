<?php

declare(strict_types=1);

namespace Rapira\Grpc\Call;

/**
 * Request metadata as gRPC defines it, on {@see Context::$metadata}: multivalued, keys
 * case-insensitive ASCII, `-bin` keys carrying binary. A flat string map would silently lose
 * duplicates and leave binary values ambiguous, which is why this is not `array<string, string>`.
 *
 * Immutable. Values of `-bin` keys arrive decoded to raw bytes — base64, padded or not, is the
 * boundary's job per the gRPC spec.
 */
final class Metadata implements \Countable, \IteratorAggregate
{
    /**
     * Every value of a key, in arrival order. Lookup is case-insensitive; a key with no values is an
     * empty list, indistinguishable from an absent one — gRPC metadata has no empty-vs-missing split.
     *
     * @return list<string> Raw bytes for a `-bin` key, ASCII otherwise.
     */
    public function values(string $name): array {}

    /**
     * The full map, keys normalized to lower case, same decoding rules as {@see self::values()}.
     *
     * @return array<lowercase-string&non-empty-string, list<string>>
     */
    public function all(): array {}

    /** @return int<0, max> Number of distinct keys. */
    public function count(): int {}

    /** @return \Iterator<lowercase-string&non-empty-string, list<string>> */
    public function getIterator(): \Iterator {}
}
