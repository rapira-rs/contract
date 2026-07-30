<?php

declare(strict_types=1);

namespace Rapira\Http\Exception;

use Rapira\Exception\RapiraThrowable;

/**
 * A body write went past the `content-length` the response head declared.
 *
 * The surplus is not sent, and neither is anything written after it. A programmer error: nobody catches it,
 * the script fatals, and the host ends the exchange with a body shorter than the handler meant to write.
 */
class ContentLengthExceededError extends \Error implements RapiraThrowable {}
