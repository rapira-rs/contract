<?php

declare(strict_types=1);

namespace Rapira\Exception;

/**
 * The dispatcher is done: no more work will ever arrive.
 *
 * Thrown once per worker lifetime, and every later call throws it again. This is the loop's exit —
 * finish the units already in flight and leave.
 */
class ClosedException extends \RuntimeException implements RapiraThrowable {}
