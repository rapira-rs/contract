<?php

declare(strict_types=1);

namespace Rapira\Grpc;

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
