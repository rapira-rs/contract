<?php

declare(strict_types=1);

namespace Rapira\WebSocket;

use Rapira\DispatcherInfo;

/**
 * The WebSocket plugin's counters, from {@see WebSocketDispatcher::getInfo()}.
 *
 * Nothing beyond the shared counters yet: this is the narrowing point where WebSocket-specific ones
 * land without touching the base contract.
 */
interface WebSocketDispatcherInfo extends DispatcherInfo {}
