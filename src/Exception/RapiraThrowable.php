<?php

declare(strict_types=1);

namespace Rapira\Exception;

/**
 * Marker for everything Rapira throws — errors included, which is what the name spans.
 *
 * For the top of the worker: a supervisor telling a Rapira failure from an application one. Not for
 * handler code — the `\Error` descendants mean "your code is wrong", and a broad catch here would
 * swallow them. A handler catches the specific exception it can answer.
 */
interface RapiraThrowable extends \Throwable {}
