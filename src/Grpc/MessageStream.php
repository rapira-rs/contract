<?php

declare(strict_types=1);

namespace Rapira\Grpc;

use Rapira\Exception\WorkDiscardedException;

/**
 * The request messages of one {@see Call\StreamingRequest} call, in arrival order: a single forward pass that ends
 * when the client half-closes — the normal end of every inbound stream, spelled as the end of
 * iteration, never as an exception.
 *
 * Advancing waits the way {@see \Rapira\Dispatcher::receive()} does: inside a fiber it suspends the
 * fiber, not the thread; outside one it blocks the process. Not advancing is the backpressure — the
 * host stops reading the client while nothing here is pulled, and the transport's flow-control window
 * does the rest. There is no unbounded buffer to overrun.
 *
 * ```php
 * foreach ($call->getMessages() as $bytes) {
 *     $in = new UploadChunk();
 *     $in->mergeFromString($bytes);
 *     // ...
 * }
 * // the client half-closed; time to respond
 * ```
 */
final class MessageStream implements \Iterator
{
    /**
     * The current message: the canonical binary-protobuf encoding of the method's input message,
     * whatever the client spoke, exactly as {@see Call\UnaryRequest::getMessage()} has it.
     */
    public function current(): string {}

    /** @return int<0, max> Zero-based index of the current message. */
    public function key(): int {}

    /** Discard the current message. Returns at once; {@see self::valid()} is where the wait lives. */
    public function next(): void {}

    /**
     * Whether a message is here — waiting, per the semantics above, until it can answer: a message
     * arrived (true) or the client half-closed (false).
     *
     * @throws WorkDiscardedException The host closed the call while waiting: deadline passed, client
     *         gone without half-closing, worker draining. Half-close is not this — it is the stream's
     *         normal end.
     */
    public function valid(): bool {}

    /**
     * A no-op before the first advance, so `foreach` works; the stream cannot restart.
     *
     * @throws \Error Already advanced — the same rule {@see \Generator::rewind()} enforces.
     */
    public function rewind(): void {}
}
