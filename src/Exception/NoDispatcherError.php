<?php

declare(strict_types=1);

namespace Rapira\Exception;

/**
 * {@see \Rapira\get_dispatcher()} was called in a mode where no dispatcher exists.
 *
 * There is no dispatcher to return: outside {@see \Rapira\Mode::Dispatcher} nothing feeds this
 * process units of work. A
 * programmer error — the script was written for a worker pool and is running somewhere else — so
 * nobody catches it and the script fatals.
 */
class NoDispatcherError extends \Error implements RapiraThrowable {}
