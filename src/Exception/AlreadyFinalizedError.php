<?php

declare(strict_types=1);

namespace Rapira\Exception;

/**
 * The worker finalized a unit of work that it had already finalized.
 *
 * A programmer error, in the {@see \TypeError} category: nobody catches it, the script fatals, the host
 * cleans up. When the *host* got there first — expired deadline, drain, client gone — the worker did
 * nothing wrong and this is not what it gets.
 */
class AlreadyFinalizedError extends \Error implements RapiraThrowable {}
