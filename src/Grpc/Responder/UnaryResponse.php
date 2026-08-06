<?php

declare(strict_types=1);

namespace Rapira\Grpc\Responder;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Grpc\Call\StreamingRequest;
use Rapira\Grpc\MethodKind;
use Rapira\Grpc\Responder;

/**
 * The response axis of {@see MethodKind::Unary} and {@see MethodKind::ClientStreaming} calls: one
 * message finishes the call.
 *
 * ```php
 * $call->respond($service->getInvoice($in)->serializeToString());
 * ```
 */
interface UnaryResponse extends Responder
{
    /**
     * Finalize the call with the response message: the canonical binary-protobuf encoding of the
     * method's output message, re-encoded by the host to whatever the client speaks.
     *
     * On a {@see StreamingRequest} call this usually follows the messages running dry, but responding
     * early is legal — gRPC lets a server answer before the client half-closes — and it finalizes the
     * call: messages not yet pulled are discarded, and the host tells the client to stop sending.
     *
     * Response headers and trailers ride the {@see Responder::getResponseMetadata()} accumulator,
     * snapshotted here, both halves at once.
     *
     * @throws AlreadyFinalizedError The call was already finalized.
     * @throws WorkDiscardedException The host closed the call before anything was committed.
     */
    public function respond(string $message): void;
}
