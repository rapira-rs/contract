<?php

declare(strict_types=1);

namespace Rapira\Exception;

/**
 * The wait for a unit of work elapsed without one becoming available.
 *
 * Routine: it can only be thrown while there is no work, so an idle loop catches it and does its
 * periodic chores. It never means the dispatcher is closed — that is {@see ClosedException}.
 */
class TimeoutException extends \RuntimeException implements RapiraThrowable {}
