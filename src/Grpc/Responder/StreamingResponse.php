<?php

declare(strict_types=1);

namespace Rapira\Grpc\Responder;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Grpc\Call\StreamingRequest;
use Rapira\Grpc\Exception\GrpcException;
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
     * Running to completion means `OK`. A {@see GrpcException} escaping mid-stream is caught here, at
     * the drain, and becomes the terminal status. A client that went away destroys the generator —
     * `finally` blocks in service code run — and the call returns normally: ordinary cancellation
     * finalizes the call, it does not fault a healthy worker.
     *
     * Response headers and trailers ride the {@see Responder::getResponseMetadata()} accumulator:
     * headers snapshot at the first yield, trailers at termination, both at once when the stream
     * terminates before yielding.
     *
     * @param \Generator<int, string> $messages
     * @throws AlreadyFinalizedError The call was already finalized.
     * @throws WorkDiscardedException The host closed the call before anything was committed.
     */
    public function respond(\Generator $messages): void;
}
