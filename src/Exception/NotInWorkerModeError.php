<?php

declare(strict_types=1);

namespace Rapira\Exception;

/**
 * {@see \Rapira\get_dispatcher()} was called outside worker mode.
 *
 * There is no dispatcher to return: in Classic mode nothing feeds this process units of work. A
 * programmer error — the script was written for a worker pool and is running somewhere else — so
 * nobody catches it and the script fatals.
 */
class NotInWorkerModeError extends \Error implements RapiraThrowable {}
