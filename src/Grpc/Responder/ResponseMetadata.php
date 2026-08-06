<?php

declare(strict_types=1);

namespace Rapira\Grpc\Responder;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Grpc\Call\Metadata;
use Rapira\Grpc\Exception\HeadersAlreadyCommittedError;
use Rapira\Grpc\Responder;

/**
 * Per-call accumulator for response headers and trailers, from {@see Responder::getResponseMetadata()}.
 * Service code adds entries anywhere during the call; the host snapshots them at the commit points.
 * For a single-message outcome both halves snapshot at finalization — the call's `respond()` or
 * {@see Responder::fail()}. For a streaming response, headers snapshot at the generator's first yield —
 * "add headers, then start yielding" is the entire commit API — and trailers when the stream
 * terminates: completed, failed, or destroyed by a gone client, whose trailer snapshot simply has no
 * destination.
 *
 * Allocated fresh with the call and dead with it; nothing survives into the worker's next call.
 *
 * ```php
 * $metadata = $call->getResponseMetadata();
 *
 * $metadata->addHeader('x-request-cost', '2.7');
 * $metadata->addBinaryHeader('x-resume-token-bin', $token->serializeToString());
 * $metadata->addTrailer('x-cache', 'hit');
 *
 * $metadata->addBinaryHeader('x-token', $bytes);   // \ValueError: missing `-bin` suffix
 * $metadata->addHeader('x-token-bin', $bytes);     // \ValueError: `-bin` key on the text method
 * $metadata->addHeader('grpc-status', '0');        // \ValueError: reserved transport namespace
 * ```
 */
final class ResponseMetadata
{
    /**
     * Add a response header. Repeating a name adds a value, never replaces one.
     *
     * @param non-empty-string $name Wire key, normalized to lower case here. Reserved transport
     *        namespaces — `grpc-*`, Connect control headers, `content-*` — are the host's to write.
     * @param string $value ASCII.
     * @throws HeadersAlreadyCommittedError The headers already left: a streaming response yielded.
     * @throws AlreadyFinalizedError The call was already finalized.
     * @throws \ValueError The name is reserved, carries the `-bin` suffix — that suffix promises
     *         binary, {@see self::addBinaryHeader()} keeps the promise — or the value is not ASCII.
     */
    public function addHeader(string $name, string $value): void {}

    /**
     * Add a binary response header. Same rules as {@see self::addHeader()}, except the name must carry
     * the `-bin` suffix — so the method's promise and the key's promise can never disagree — and $bytes
     * are raw: base64, emitted unpadded per the gRPC spec, is the boundary's job.
     *
     * @param non-empty-string $name
     * @throws HeadersAlreadyCommittedError
     * @throws AlreadyFinalizedError
     * @throws \ValueError The name is reserved or lacks the `-bin` suffix.
     */
    public function addBinaryHeader(string $name, string $bytes): void {}

    /**
     * Add a response trailer. Same rules as {@see self::addHeader()}, but trailers stay open for the
     * whole call — streaming is where they earn their keep — and close only with its finalization.
     *
     * @param non-empty-string $name
     * @param string $value ASCII.
     * @throws AlreadyFinalizedError The call was already finalized.
     * @throws \ValueError As {@see self::addHeader()}.
     */
    public function addTrailer(string $name, string $value): void {}

    /**
     * Add a binary response trailer. Same rules as {@see self::addBinaryHeader()}, same lifetime as
     * {@see self::addTrailer()}.
     *
     * @param non-empty-string $name
     * @throws AlreadyFinalizedError
     * @throws \ValueError
     */
    public function addBinaryTrailer(string $name, string $bytes): void {}

    /** What has been accumulated so far, headers half. */
    public function headers(): Metadata {}

    /** What has been accumulated so far, trailers half. */
    public function trailers(): Metadata {}
}
