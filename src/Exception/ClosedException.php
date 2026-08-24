<?php

declare(strict_types=1);

namespace Rapira\Exception;

/**
 * The source is exhausted: no more will ever arrive. From a {@see \Rapira\Dispatcher} that is the
 * drain — the worker loop's exit: finish the units already in flight and leave. From a
 * {@see \Rapira\Grpc\Call\MessageStream} it is the client's half-close — the normal end of every
 * inbound stream.
 *
 * Thrown once per source, and every later call throws it again.
 */
class ClosedException extends \RuntimeException implements RapiraThrowable {}
