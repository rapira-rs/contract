<?php

declare(strict_types=1);

namespace Rapira\WebSocket;

use Rapira\DispatcherInfo;

/**
 * The WebSocket plugin's counters, from {@see WebSocketDispatcher::getInfo()}.
 */
interface WebSocketDispatcherInfo extends DispatcherInfo
{
    /** @return int<0, max> Connections the host holds open for this plugin, across every worker. */
    public function connectionCount(): int;
}
