<?php

declare(strict_types=1);

namespace Rapira\Grpc;

use Rapira\Work;

/**
 * The reading side of one RPC — {@see Responder} is the answering side, and
 * {@see GrpcDispatcher::receive()} hands out one object implementing both. A layer given only this
 * half reads the call and cannot answer it.
 *
 * The request side forks by the method's shape: {@see Call\UnaryRequest} holds one message,
 * {@see Call\StreamingRequest} a stream still arriving.
 */
interface Call extends Work
{
    /**
     * The call's context: the request data, whole and immutable — method, metadata, deadline, peer.
     */
    public function getContext(): Context;
}
