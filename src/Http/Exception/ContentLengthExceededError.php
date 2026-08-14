<?php

declare(strict_types=1);

namespace Rapira\Http\Exception;

use Rapira\Exception\RapiraThrowable;

/**
 * The body went past the `content-length` the response head declared.
 *
 * The surplus is caught where it is known. A {@see \Rapira\Http\Exchange::writeBody()} discovers it
 * as the bytes arrive, so the chunk is truncated: the surplus is not sent, and neither is anything
 * written after it. A {@see \Rapira\Http\Exchange::sendFile()} knows its length up front and is
 * rejected whole, before anything is written. Either way a programmer error: nobody catches it, the
 * script fatals, and the host ends the exchange with a body shorter than the handler meant to write.
 */
class ContentLengthExceededError extends \Error implements RapiraThrowable {}
