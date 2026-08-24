<?php

declare(strict_types=1);

namespace Rapira\Exception;

/**
 * The wait — for a unit of work, for a stream's next message — elapsed without one becoming
 * available.
 *
 * Routine: it can only be thrown while there is nothing, so an idle loop catches it and does its
 * periodic chores. It never means the source is closed — that is {@see ClosedException}.
 */
class TimeoutException extends \RuntimeException implements RapiraThrowable {}
