<?php

declare(strict_types=1);

namespace Rapira;

if (!\extension_loaded('rapira')) {
    /**
     * Version of the running Rapira server.
     *
     * @return non-empty-string
     */
    function get_version(): string
    {
        return '0.0.0';
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
        return Mode::Classic;
    }

    /**
     * The plugins the running host serves, by the names their {@see Dispatcher::name()} answers — the root
     * sections of `rapira.toml`: `http`, `grpc`, `websocket`.
     *
     * The same list in every process the host started, in every mode, fixed for the life of the process.
     * Empty in a process the host did not start — a console command, a supervisor-run consumer.
     *
     * @return list<non-empty-string>
     */
    function get_plugins(): array
    {
        return [];
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
     */
    function handle_request(callable $handler): bool
    {
        return false;
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
        throw new Exception\NoDispatcherError('Dispatcher is only available in Mode::Dispatcher');
    }

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
    {
        \error_log(json_encode([
            'level' => $level->name,
            'message' => $message,
            'context' => $context,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
}

namespace Rapira\WebSocket;

if (!\extension_loaded('rapira')) {
    /**
     * A WebSocket connection this host holds, by its {@see Connection::getId()} — from every pool the host
     * started, in any mode, so an HTTP handler or a job pushes to a client the same way a `websocket` worker
     * does.
     *
     * Never checks liveness: any string yields a handle, and an id that is unknown, malformed, held by
     * another host or whose connection has ended discards what it sends, as on any connection that is
     * closing or closed. Ids are never reused, so a stale one reaches nobody.
     *
     * @throws Exception\NoWebSocketError The host serves no `websocket` section, or this process was not
     *         started by the host — {@see \Rapira\get_plugins()} lacks `websocket` in both cases.
     */
    function get_connection(string $id): Connection
    {
        throw new Exception\NoWebSocketError('WebSocket connections exist only on a host serving the websocket section');
    }
}
