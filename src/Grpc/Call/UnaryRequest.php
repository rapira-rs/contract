<?php

declare(strict_types=1);

namespace Rapira\Grpc\Call;

use Rapira\Grpc\Call;
use Rapira\Grpc\MethodInfo;
use Rapira\Grpc\MethodKind;

/**
 * The request axis of {@see MethodKind::Unary} and {@see MethodKind::ServerStreaming} calls: one
 * message, in hand before dispatch.
 *
 * ```php
 * $in = new GetInvoiceRequest();
 * $in->mergeFromString($call->getMessage());
 * ```
 */
interface UnaryRequest extends Call
{
    /**
     * The request message: the canonical binary-protobuf encoding of the method's input message.
     * Decode it with the generated class {@see MethodInfo::$inputType} names.
     */
    public function getMessage(): string;
}
