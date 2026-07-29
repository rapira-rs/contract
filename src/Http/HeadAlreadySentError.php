<?php

declare(strict_types=1);

namespace Rapira\Http;

use Rapira\Exception\RapiraException;

/**
 * The response head had already committed when the worker tried to write it, or to send an early hint.
 *
 * A programmer error: status and headers go out once, and after that the only thing left to write is
 * body. Nobody catches this — the script fatals and the host finishes the exchange. Writing body after
 * the response ended is a different thing, {@see \Rapira\Exception\AlreadyFinalizedError}.
 */
class HeadAlreadySentError extends \Error implements RapiraException {}
