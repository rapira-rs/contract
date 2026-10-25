<?php

declare(strict_types=1);

namespace Rapira\Exception;

/**
 * The source is exhausted: no more will arrive from it. From a {@see \Rapira\Dispatcher} it ends this
 * app instance: finish the units already in flight and leave the worker loop. The next app instance in
 * the same worker gets the units that wait. From a {@see \Rapira\Grpc\Call\MessageStream} it is the
 * client's half-close — the normal end of every inbound stream.
 *
 * Thrown once per source, and every later call throws it again.
 */
class ClosedException extends \RuntimeException implements RapiraThrowable {}
