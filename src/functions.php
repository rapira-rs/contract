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
 *        The `exception` key is special: if present, it must be an `\Exception` or `\Throwable` and will be serialized
 *        as a structured error.
 */
function log(string $message, LogLevel $level = LogLevel::Info, array $context = []): void
{}
