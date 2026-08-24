<?php

declare(strict_types=1);

namespace Rapira\Grpc\Responder;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Grpc\Call\MessageStream;
use Rapira\Grpc\Call\StreamingRequest;
use Rapira\Grpc\MethodKind;
use Rapira\Grpc\Responder;

/**
 * The response axis of {@see MethodKind::ServerStreaming} and {@see MethodKind::BidiStreaming} calls:
 * a stream of messages, drained from a generator, finishes the call.
 *
 * ```php
 * // server-streaming: the request is already in hand
 * $call->respond((static function () use ($service, $in): \Generator {
 *     foreach ($service->watch($in) as $out) {
 *         yield $out->serializeToString();
 *     }
 * })());
 *
 * // bidi: the generator reads the request messages between yields
 * $call->respond((static function () use ($call, $service): \Generator {
 *     foreach ($call->getMessages() as $bytes) {
 *         yield $service->answer($bytes)->serializeToString();
 *     }
 * })());
 * ```
 */
interface StreamingResponse extends Responder
{
    /**
     * Finalize the call by draining a response stream: one message framed and flushed per yielded
     * string — each the canonical binary-protobuf encoding of the method's output message —
     * backpressure being the generator simply not resumed while the transport's window is closed.
     *
     * The call does not return until the stream terminates: the worker is single-threaded, so the host
     * can pump the generator only while PHP is inside this call, and the context stays installed
     * across the drain. On a {@see StreamingRequest} call the generator reads
     * {@see StreamingRequest::getMessages()} between yields — a nested native wait on the same fiber,
     * which is what makes bidi bidirectional — and ending the response ends the call: request messages
     * not yet pulled are discarded, and the host tells the client to stop sending.
     *
     * Running to completion means `OK`. A throwable escaping the generator escapes this method
     * unchanged — the host interprets no exception: choosing the status, or catching inside a wrapping
     * generator and continuing the stream, is the caller's business. The call is left unfinalized —
     * committed headers and sent messages stand, and gRPC carries errors in trailers anyway — so
     * {@see Responder::fail()} still finalizes it.
     *
     * The host closing the call — deadline passed, client gone, worker draining — arrives the way
     * every wait point delivers that fact: as {@see WorkDiscardedException}, thrown into the generator
     * at the yield it rests on ({@see \Generator::throw()}), never by destroying it. The generator
     * decides — catch it to salvage progress and finish work that needs no wire, or let it fly;
     * `finally` runs by ordinary unwinding either way. The closure itself finalized the call, so every
     * further yield throws afresh — the message has no destination — and metadata added in the catch
     * meets {@see AlreadyFinalizedError}. A generator that catches and runs to its end returns this
     * method normally, and no `OK` is sent: completing after closure is survival, not success. When
     * the generator is waiting inside {@see StreamingRequest::getMessages()} instead, the
     * {@see MessageStream} wait it rests in — `next()` or the iteration — delivers the same
     * exception: one delivery, at whichever wait point is active.
     *
     * Response headers and trailers ride the {@see Responder::getResponseMetadata()} accumulator:
     * headers snapshot at the first yield, trailers at termination, both at once when the stream
     * terminates before yielding.
     *
     * @param \Generator<int, string> $messages
     * @throws AlreadyFinalizedError The call was already finalized.
     * @throws WorkDiscardedException The host closed the call before the drain began — thrown without
     *         starting the generator, as the unary path throws it — or the generator let the delivered
     *         one escape.
     */
    public function respond(\Generator $messages): void;
}
