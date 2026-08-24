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
 * The host never catches it — no exception is interpreted across the boundary: one escaping a
 * response generator escapes `respond()` unchanged, and one escaping the worker script is a bug like
 * any other `Throwable` — the host fails the in-flight call as a sanitized `INTERNAL` and recycles
 * the worker. It is in the contract so that every SDK and library throws the same spelling, and one
 * adapter catch — `$call->fail($e->status)` — serves every method kind.
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
     * The {@see Status} triple, in its order — the code stated, never defaulted: `Internal` is the
     * host's spelling of a bug, not a fallback for a forgotten argument.
     *
     * @param list<\Rapira\Grpc\ErrorDetail> $details
     */
    public function __construct(
        StatusCode $code,
        string $message = '',
        array $details = [],
    ) {
        parent::__construct($message);
        $this->status = new Status($code, $message, $details);
    }
}
