<?php

declare(strict_types=1);

namespace Rapira\Exception;

/**
 * {@see \Rapira\handle_request()} was called outside {@see \Rapira\Mode::Worker}.
 *
 * In any other mode the host does not hand requests out one by one, so there is no next request to serve.
 * A programmer error — a worker loop is running in a process launched for another mode — so nobody
 * catches it and the script fatals.
 */
class NotInWorkerModeError extends \Error implements RapiraThrowable {}
