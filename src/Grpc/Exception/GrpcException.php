<?php

declare(strict_types=1);

namespace Rapira\Grpc\Exception;

use Rapira\Exception\RapiraThrowable;
use Rapira\Grpc\Status;
use Rapira\Grpc\StatusCode;

/**
 * The throwable spelling of a {@see Status}: data first, exception as a thin thrower over it — the
 * split grpc-go and grpc-java both draw.
 *
 * It is in the contract because the host itself matches on it: a `GrpcException` escaping a streaming
 * generator is caught at the drain and becomes the terminal status, framed per the client's protocol.
 * On the unary path it is adapter vocabulary — catch it around the service call and finalize with
 * `$call->fail($e->status)`. Either way, any *other* `Throwable` is a bug, not a status: the script
 * fatals, the host fails the in-flight call as a sanitized `INTERNAL` and recycles the worker.
 *
 * Curated subclasses that pin a code — `NotFoundException` narrowing the constructor to
 * `(string $message, array $details)` — are one `parent::__construct()` call each, so they live in the
 * SDK, not here.
 *
 * The property is `$status`, not `$code`: `\Exception::$code` already exists as an untyped `int` and
 * cannot be redeclared.
 */
class GrpcException extends \RuntimeException implements RapiraThrowable
{
    public readonly Status $status;

    /**
     * @param list<\Rapira\Grpc\ErrorDetail> $details
     */
    public function __construct(
        string $message = '',
        StatusCode $code = StatusCode::Internal,
        array $details = [],
    ) {
        parent::__construct($message);
        $this->status = new Status($code, $message, $details);
    }
}
