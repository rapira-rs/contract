<?php

declare(strict_types=1);

namespace Rapira\Grpc\Call;

/**
 * The protocol a call arrived over, on {@see Context::$protocol}. Diagnostic only: the host normalizes
 * framing, compression, deadlines and error encoding before dispatch, so worker code never branches on
 * this — it goes into a log field and nothing else.
 */
enum Protocol: string
{
    /** Native gRPC over HTTP/2: length-prefixed binary frames, the status in trailers. */
    case Grpc = 'grpc';

    /** gRPC for browsers: the same frames over any HTTP version, the trailers folded into the body. */
    case GrpcWeb = 'grpc-web';

    /** Connect: plain HTTP with proto or JSON per `Content-Type`, the status as an HTTP code plus error JSON. */
    case Connect = 'connect';

    /**
     * Plain HTTP+JSON mapped onto a method by its `google.api.http` annotation. The host resolves the
     * path and decodes the JSON, so the worker sees a `package.Service/Method` call like any other.
     */
    case Rest = 'rest';
}
