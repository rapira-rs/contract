<?php

declare(strict_types=1);

namespace Rapira\WebSocket\Exception;

use Rapira\Exception\RapiraThrowable;

/**
 * {@see \Rapira\WebSocket\get_connection()} was called on a host that serves no `websocket` section, or in
 * a process the host did not start.
 *
 * There are no connections to hand out. A programmer error, like
 * {@see \Rapira\Exception\NoDispatcherError}: {@see \Rapira\get_plugins()} answers the question
 * beforehand, so nobody catches this and the script fatals.
 */
class NoWebSocketError extends \Error implements RapiraThrowable {}
