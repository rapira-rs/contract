<?php

declare(strict_types=1);

namespace Rapira\Http;

use Rapira\Exception\RapiraException;

/**
 * The final response head had already been written when the worker tried to write another one.
 *
 * A programmer error: the status and its fields go out once, and after that the only thing left to write
 * is body — an interim `1xx` head is no longer accepted either. Nobody catches this: the script fatals
 * and the host finishes the exchange. Writing body after the response ended is a different thing,
 * {@see \Rapira\Exception\AlreadyFinalizedError}.
 */
class HeadAlreadySentError extends \Error implements RapiraException {}
