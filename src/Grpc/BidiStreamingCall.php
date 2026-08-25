<?php

declare(strict_types=1);

namespace Rapira\Grpc;

use Rapira\Grpc\Call\StreamingRequest;
use Rapira\Grpc\Responder\StreamingResponder;

/**
 * A {@see MethodKind::BidiStreaming} call, whole: the request messages still arriving, a drained
 * generator that reads them between its yields finalizes it.
 *
 * A name for one point of the axes' product, nothing of its own: dispatching by kind is one
 * `instanceof`, and a layer that cares about a single axis types against {@see StreamingRequest} or
 * {@see StreamingResponder} as before.
 */
interface BidiStreamingCall extends StreamingRequest, StreamingResponder {}
