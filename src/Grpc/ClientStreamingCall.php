<?php

declare(strict_types=1);

namespace Rapira\Grpc;

/**
 * A {@see MethodKind::ClientStreaming} call, whole: the request messages still arriving, one message
 * finalizes it.
 *
 * A name for one point of the axes' product, nothing of its own: dispatching by kind is one
 * `instanceof`, and a layer that cares about a single axis types against {@see StreamingRequest} or
 * {@see UnaryResponder} as before.
 */
interface ClientStreamingCall extends StreamingRequest, UnaryResponder {}
