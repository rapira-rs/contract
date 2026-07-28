<?php

declare(strict_types=1);

namespace Rapira\Exception;

/**
 * The host had already closed the unit, so the outcome the worker produced was discarded.
 *
 * No rule was broken: the deadline expired, the worker is draining, the client left, or the delivery
 * lease went to another worker. Ask {@see \Rapira\Work::isCancelled()} at checkpoints to avoid doing the
 * work at all; catch this to log the loss. Finalizing twice yourself is a different thing —
 * {@see AlreadyFinalizedError}.
 */
class WorkDiscardedException extends \RuntimeException implements RapiraException {}
