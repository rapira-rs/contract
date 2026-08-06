<?php

declare(strict_types=1);

namespace Rapira\Grpc\Call;

use Rapira\Grpc\Call;
use Rapira\Grpc\MethodKind;

/**
 * The request axis of {@see MethodKind::ClientStreaming} and {@see MethodKind::BidiStreaming} calls:
 * a stream, still arriving when the worker takes the call — it is handed out on its first message.
 *
 * ```php
 * foreach ($call->getMessages() as $bytes) {
 *     $chunk = new UploadChunk();
 *     $chunk->mergeFromString($bytes);
 *     // ...
 * }
 * // the client half-closed
 * ```
 */
interface StreamingRequest extends Call
{
    /**
     * The request messages, as they arrive. The same stream on every call: one forward pass, shared
     * position — wherever the adapter hands it, everyone advances the same cursor.
     */
    public function getMessages(): MessageStream;
}
