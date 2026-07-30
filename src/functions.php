<?php

declare(strict_types=1);

namespace Rapira;

/**
 * Version of the running Rapira server.
 *
 * @return non-empty-string
 */
function get_version(): string
{
    return '0.1.0';
}

/**
 * Get the current dispatcher instance.
 * Returns the same instance for the life of the process.
 *
 * @throws Exception\NotInWorkerModeError Called outside worker mode — nothing dispatches work to
 *         this process, so there is nothing to return.
 */
function get_dispatcher(): Dispatcher
{}

/**
 * Write a diagnostic to Rapira's log under the `app` target.
 *
 * Never blocks: the message is queued and the host writes it.
 * Never throws: diagnostics are emitted from `catch` blocks, where an exception from the logger
 * would bury the original error.
 * On queue overflow the message is dropped and the host reports the loss itself.
 *
 * @param array<non-empty-string, mixed> $context JSON-serializable context for structured logging.
 *        The `exception` key is special: when present it must be a `\Throwable`, serialized as a
 *        structured error. A value that cannot be serialized does not throw either: the record is
 *        kept, the value is replaced with a placeholder, and the loss is noted in the record itself.
 */
function log(string $message, LogLevel $level = LogLevel::Info, array $context = []): void
{}
