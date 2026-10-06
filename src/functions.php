<?php

declare(strict_types=1);

namespace Rapira {

use Rapira\Internal\Double;

if (!\function_exists('Rapira\get_version')) {
    /**
     * Version of the running Rapira server.
     *
     * @return non-empty-string
     */
    function get_version(): string
    {
        return Double::runtime()->version();
    }

    /**
     * The mode Rapira is running this process in.
     *
     * Fixed for the life of the process: the host settles it at launch from `[pool] mode`, and nothing
     * moves a running process between modes. Unlike {@see get_dispatcher()} it answers in every mode —
     * reading the mode is how code tells whether asking for a dispatcher would even be valid, which it
     * is only in {@see Mode::Dispatcher}.
     */
    function get_mode(): Mode
    {
        return Double::runtime()->mode();
    }

    /**
     * Serve the next request in {@see Mode::Worker}.
     *
     * Blocks until the host has a request, exposes it through the SAPI superglobals and runs $handler for
     * it; the response is whatever the handler emitted through the SAPI — `header()`, `echo` — by the time
     * it returns. Returns false once the host will hand out no more requests, so the worker loop is
     * `while (handle_request($handler));`. Requests are not units of work here, which is why
     * {@see Mode::Dispatcher} serves them from {@see get_dispatcher()} instead.
     *
     * @param callable(): bool $handler
     *
     * @throws Exception\NotInWorkerModeError Called outside {@see Mode::Worker} — the host hands requests
     *         to this process some other way, or not at all.
     */
    function handle_request(callable $handler): bool
    {
        return Double::runtime()->handleRequest($handler);
    }

    /**
     * Get the current dispatcher instance.
     * Returns the same instance for the life of the process.
     *
     * @throws Exception\NoDispatcherError Called outside {@see Mode::Dispatcher} — nothing dispatches
     *         work to this process, so there is nothing to return.
     */
    function get_dispatcher(): Dispatcher
    {
        return Double::runtime()->dispatcher();
    }

    /**
     * Write a diagnostic to Rapira's log under the `app` target.
     *
     * Never blocks: the message is queued and the host writes it.
     * Never throws: diagnostics are emitted from `catch` blocks, where an exception from the logger
     * would bury the original error.
     * On queue overflow the message is dropped and the host reports the loss itself.
     *
     * @param array<array-key, mixed> $context Attached to the record as a single JSON string; a list
     *        encodes as a JSON array, anything else as an object.
     *        A `\Throwable` under any top-level key is serialized as a structured error — class, message,
     *        code, file, line and its chain of previous exceptions — since `json_encode()` would see none
     *        of that private state; nested deeper, it serializes as an empty object.
     *        A value that cannot be serialized does not throw either: the record is kept and the value
     *        becomes `null`, with nothing in the record marking the loss.
     */
    function log(string $message, LogLevel $level = LogLevel::Info, array $context = []): void
    {
        Double::runtime()->log($message, $level, $context);
    }
}

}

namespace {

use Rapira\Internal\Double;

if (!\function_exists('rapira_finish_request')) {
    /**
     * Send the response to the client now, in {@see \Rapira\Mode::Classic} and {@see \Rapira\Mode::Worker}.
     *
     * The script keeps running after it, but nothing it emits reaches the client any more. A userland
     * output handler that fails while being flushed fails the request like a fatal error.
     *
     * @throws \Error Called in {@see \Rapira\Mode::Dispatcher}: there the response belongs to the unit
     *         {@see \Rapira\Dispatcher::receive()} returned, and finalizing that unit is what sends it.
     */
    function rapira_finish_request(): bool
    {
        return Double::runtime()->finishRequest();
    }
}

}
