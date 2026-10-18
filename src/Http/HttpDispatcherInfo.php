<?php

declare(strict_types=1);

namespace Rapira\Http;

use Rapira\DispatcherInfo;

/**
 * The HTTP plugin's counters, from {@see HttpDispatcher::getInfo()}, plus the server identity its section
 * of `rapira.toml` configures.
 */
interface HttpDispatcherInfo extends DispatcherInfo
{
    /**
     * The `server_name` of the `http` section, `localhost` when unset. Worker mode puts it in `SERVER_NAME`;
     * the client's `Host` is {@see Request::$authority}, never this.
     */
    public function serverName(): string;

    /**
     * @return int<0, 65535> The `server_port` of the `http` section; when unset, the port the section
     *         listens on, or 80 on a unix socket. Worker mode puts it in `SERVER_PORT`.
     */
    public function serverPort(): int;
}
