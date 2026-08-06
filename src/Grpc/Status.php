<?php

declare(strict_types=1);

namespace Rapira\Grpc;

/**
 * The `google.rpc.Status` triple: the data an RPC fails with, taken by {@see Responder::fail()}.
 *
 * The host encodes it once per protocol — a serialized `google.rpc.Status` in
 * `grpc-status-details-bin` for gRPC and gRPC-Web, structured JSON details for Connect — so everything
 * expressible in rich gRPC errors (`RetryInfo`, `BadRequest` field violations, `ErrorInfo`, …) is
 * expressible here. The exception layer is a thin thrower over this,
 * {@see Exception\GrpcException::$status}.
 */
final readonly class Status
{
    /**
     * @param string $message Developer-facing, in English; the client sees it verbatim. Anything
     *        localized or structured belongs in $details.
     * @param list<ErrorDetail> $details Packed detail messages, each a `google.protobuf.Any` pair.
     */
    public function __construct(
        public StatusCode $code,
        public string $message = '',
        public array $details = [],
    ) {}
}
