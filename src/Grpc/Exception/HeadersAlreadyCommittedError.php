<?php

declare(strict_types=1);

namespace Rapira\Grpc\Exception;

use Rapira\Exception\RapiraThrowable;

/**
 * The response headers had already left when the worker tried to add another one: a streaming
 * response's first yield is their commit point. After it only trailers stay open, and a trailer
 * added there reaches the client through {@see \Rapira\Grpc\Responder::fail()}.
 *
 * A programmer error, gRPC's spelling of {@see \Rapira\Http\Exception\HeadAlreadyWrittenError}'s fact: nobody catches this,
 * the script fatals and the host finishes the call. Adding metadata to a call that already ended is a
 * different thing, {@see \Rapira\Exception\AlreadyFinalizedError}.
 */
class HeadersAlreadyCommittedError extends \Error implements RapiraThrowable {}
