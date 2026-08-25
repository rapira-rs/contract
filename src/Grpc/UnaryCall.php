<?php

declare(strict_types=1);

namespace Rapira\Grpc;

use Rapira\Grpc\Call\UnaryRequest;
use Rapira\Grpc\Responder\UnaryResponder;

/**
 * A {@see MethodKind::Unary} call, whole: the one request message in hand, one message finalizes it.
 *
 * A name for one point of the axes' product, nothing of its own: dispatching by kind is one
 * `instanceof`, and a layer that cares about a single axis types against {@see UnaryRequest} or
 * {@see UnaryResponder} as before.
 */
interface UnaryCall extends UnaryRequest, UnaryResponder {}
