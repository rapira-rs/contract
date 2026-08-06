<?php

declare(strict_types=1);

namespace Rapira\Grpc;

use Rapira\DispatcherInfo;

/**
 * The gRPC plugin's counters, from {@see GrpcDispatcher::getInfo()}.
 *
 * Nothing beyond the shared counters yet: this is the narrowing point where gRPC-specific ones
 * land without touching the base contract.
 */
interface GrpcDispatcherInfo extends DispatcherInfo {}
