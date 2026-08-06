<?php

declare(strict_types=1);

namespace Rapira\Grpc;

/**
 * The closed gRPC status-code set, minus `OK`: {@see Responder::fail()} is the only consumer, a
 * successful call is its type's `respond()`, and a type that cannot spell "failed with OK" is
 * worth one missing case. Values are the wire numbers.
 */
enum StatusCode: int
{
    case Cancelled = 1;
    case Unknown = 2;
    case InvalidArgument = 3;
    case DeadlineExceeded = 4;
    case NotFound = 5;
    case AlreadyExists = 6;
    case PermissionDenied = 7;
    case ResourceExhausted = 8;
    case FailedPrecondition = 9;
    case Aborted = 10;
    case OutOfRange = 11;
    case Unimplemented = 12;
    case Internal = 13;
    case Unavailable = 14;
    case DataLoss = 15;
    case Unauthenticated = 16;
}
