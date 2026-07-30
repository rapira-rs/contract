<?php

declare(strict_types=1);

namespace Rapira\Http\Exception;

use Rapira\Exception\RapiraThrowable;

/**
 * A trailer section was written while no final head had been committed.
 *
 * Nothing on the way to a trailer section commits a head implicitly — that is
 * {@see \Rapira\Http\Exchange::writeHead()}'s or {@see \Rapira\Http\Exchange::writeBody()}'s job. A programmer error: nobody
 * catches it, the script fatals, the host finishes the exchange.
 */
class HeadNotWrittenError extends \Error implements RapiraThrowable {}
