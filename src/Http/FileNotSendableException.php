<?php

declare(strict_types=1);

namespace Rapira\Http;

use Rapira\Exception\RapiraException;

/**
 * The host could not send the file {@see Exchange::sendFile()} named: nothing at that path, not a regular
 * file, no permission to read it, or the requested slice runs past its end.
 *
 * Nothing has been written when this is raised, so a handler that has not committed a head yet can still
 * answer `404` or `500` — which is the whole reason it is catchable.
 */
class FileNotSendableException extends \RuntimeException implements RapiraException {}
