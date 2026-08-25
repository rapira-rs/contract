<?php

declare(strict_types=1);

namespace Rapira\Grpc\Call;

use Rapira\Dispatcher;
use Rapira\Grpc\StreamingRequest;
use Rapira\Grpc\UnaryRequest;
use Rapira\Exception\ClosedException;
use Rapira\Exception\TimeoutException;
use Rapira\Exception\WorkDiscardedException;

/**
 * The request messages of one {@see StreamingRequest} call, in arrival order: {@see Dispatcher}
 * vocabulary at message grain. `next()` waits, `tryNext()` polls, and the client's half-close — the
 * normal end of every inbound stream — is {@see ClosedException}, so the message loop is the worker
 * loop's shape one level down:
 *
 * ```php
 * try {
 *     while (true) {
 *         $in = new UploadChunk();
 *         $in->mergeFromString($stream->next());
 *         // ...
 *     }
 * } catch (ClosedException) {
 *     // the client half-closed; time to respond
 * }
 * ```
 *
 * Not pulling is the backpressure: the host stops reading the client while nothing here is pulled,
 * and the transport's flow-control window does the rest — there is no unbounded buffer to overrun.
 */
interface MessageStream extends \IteratorAggregate
{
    /**
     * Wait up to $timeout for the next message, with {@see Dispatcher::receive()}'s waiting
     * semantics: inside a fiber it suspends the fiber, not the thread; outside one it blocks the
     * process.
     *
     * @param int<-1, max> $timeout Microseconds to wait; -1 waits indefinitely, 0 does not wait at
     *        all and throws {@see TimeoutException} at once when nothing has arrived — unlike
     *        {@see self::tryNext()}, which returns null for that.
     * @return string The canonical binary-protobuf encoding of the method's input message, whatever
     *         the client spoke, exactly as {@see UnaryRequest::getMessage()} has it.
     * @throws TimeoutException No message arrived within $timeout. Never the stream's end — that is
     *         {@see ClosedException}.
     * @throws ClosedException The client half-closed: no more messages will ever arrive — the
     *         stream's normal end, and every later call throws it again.
     * @throws WorkDiscardedException The host closed the call: deadline passed, client gone without
     *         half-closing, worker draining. Half-close is not this — it is the stream's normal end.
     */
    public function next(int $timeout = -1): string;

    /**
     * Take a message if one has already arrived. Never waits.
     *
     * @return string|null Null means nothing has arrived at this moment; the stream may fill again.
     *         A message, null and {@see ClosedException} are the three answers — open with a fact,
     *         open and empty, ended — so polling liveness needs no method of its own.
     * @throws ClosedException The client half-closed, as {@see self::next()}.
     * @throws WorkDiscardedException The host closed the call, as {@see self::next()}.
     */
    public function tryNext(): ?string;

    /**
     * The stream as an `iterable` — `next(-1)` in a loop, ending at the half-close. It is contract
     * because being iterable is a type-level fact no wrapper can add: a `stream Chunk` parameter
     * typed `iterable` takes the stream itself.
     *
     * A view, never a copy: iteration advances the same shared cursor, so `break` then
     * {@see self::tryNext()} continues where the loop stopped, and a second call iterates the
     * remainder. {@see WorkDiscardedException} escapes the iteration as it escapes `next()`;
     * {@see TimeoutException} never does — the wait is indefinite.
     *
     * @return \Traversable<mixed, string> Yields messages; keys carry no promise.
     */
    public function getIterator(): \Traversable;
}
