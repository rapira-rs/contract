<?php

declare(strict_types=1);

namespace Rapira\WebSocket\Exception;

use Rapira\Exception\RapiraThrowable;

/**
 * {@see \Rapira\WebSocket\get_connection()} was called on a host that serves no `websocket` section.
 *
 * There are no connections to hand out. A programmer error, like
 * {@see \Rapira\Exception\NoDispatcherError}: the code was written for a host that is not this one, so
 * nobody catches it and the script fatals.
 */
class NoWebSocketError extends \Error implements RapiraThrowable {}
