<?php

declare(strict_types=1);

namespace Rapira\Grpc;

/**
 * One packed message of {@see Status::$details}: the two fields of a `google.protobuf.Any`. Service
 * code serializes the detail with its generated protobuf classes and hands over the pair; the host
 * never interprets the bytes.
 */
final readonly class ErrorDetail
{
    /**
     * @param non-empty-string $typeUrl `type.googleapis.com/google.rpc.RetryInfo`.
     * @param string $value The packed message bytes — `Any.value`, raw.
     */
    public function __construct(
        public string $typeUrl,
        public string $value,
    ) {}
}
