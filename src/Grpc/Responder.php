<?php

declare(strict_types=1);

namespace Rapira\Grpc;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Grpc\Responder\ResponseMetadata;
use Rapira\Work;

/**
 * The answering side of one RPC — {@see Call} is the reading side, and
 * {@see GrpcDispatcher::receive()} hands out one object implementing both. A layer given only this
 * half answers the call and cannot read it.
 *
 * The success verb lives on the response axis — {@see Responder\UnaryResponse} takes one message,
 * {@see Responder\StreamingResponse} drains a generator. Failing lives here: every kind fails the
 * same way.
 */
interface Responder extends Work
{
    /**
     * The accumulator response headers and trailers go through, on success and failure alike.
     * Mutable and call-scoped: entries are accepted until their half's commit point, snapshotted
     * there, and the accumulator dies with the call.
     */
    public function getResponseMetadata(): ResponseMetadata;

    /**
     * Finalize the call with a status error: the `google.rpc.Status` triple, encoded by the host once
     * per protocol — `grpc-status` trailers for gRPC, the trailer frame for gRPC-Web, an HTTP status
     * plus error JSON for Connect. The {@see self::getResponseMetadata()} accumulator is snapshotted
     * here too.
     *
     * @throws AlreadyFinalizedError The call was already finalized.
     * @throws WorkDiscardedException The host closed the call first.
     */
    public function fail(Status $status): void;
}
