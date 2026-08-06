<?php

declare(strict_types=1);

namespace Rapira\Grpc;

/**
 * The protocol a call arrived over, on {@see Context::$protocol}. Diagnostic only: the host normalizes
 * framing, compression, deadlines and error encoding before dispatch, so worker code never branches on
 * this — it goes into a log field and nothing else.
 */
enum Protocol: string
{
    case Grpc = 'grpc';
    case GrpcWeb = 'grpc-web';
    case Connect = 'connect';
}
