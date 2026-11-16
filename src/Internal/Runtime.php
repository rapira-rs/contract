<?php

declare(strict_types=1);

namespace Rapira\Internal;

use Rapira\Dispatcher;
use Rapira\Exception\NoDispatcherError;
use Rapira\Exception\NotInWorkerModeError;
use Rapira\LogLevel;
use Rapira\Mode;

/**
 * What the `Rapira\*` stub functions answer outside a Rapira process.
 *
 * Every method backs the function of the same meaning. This class answers as a process the host never
 * launched: {@see Mode::Classic}, no worker requests, no dispatcher, no object store to read — an empty
 * answer would pass for "nothing leaks". A test double extends it and is put
 * in place with {@see Double::setRuntime()}.
 *
 * @internal Extended by `rapira/testing`; tests go through its doubles.
 */
class Runtime
{
    /**
     * @see \Rapira\get_version()
     *
     * @return non-empty-string
     */
    public function version(): string
    {
        return '0.0.0';
    }

    /**
     * @see \Rapira\get_mode()
     */
    public function mode(): Mode
    {
        return Mode::Classic;
    }

    /**
     * @see \Rapira\handle_request()
     *
     * @param callable(): bool $handler
     */
    public function handleRequest(callable $handler): bool
    {
        throw new NotInWorkerModeError('Requests are only handed out in Mode::Worker');
    }

    /**
     * @see \Rapira\get_dispatcher()
     */
    public function dispatcher(): Dispatcher
    {
        throw new NoDispatcherError('Dispatcher is only available in Mode::Dispatcher');
    }

    /**
     * @see \Rapira\log()
     *
     * @param array<array-key, mixed> $context
     */
    public function log(string $message, LogLevel $level, array $context): void
    {
        \error_log(\json_encode([
            'level' => $level->name,
            'message' => $message,
            'context' => $context,
        ], \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
    }

    /**
     * @see \Rapira\count_objects()
     *
     * @return array<class-string, int<1, max>>
     */
    public function countObjects(): array
    {
        throw new \Error('Counting objects needs the Rapira extension');
    }

    /**
     * @see \Rapira\get_objects()
     *
     * @param list<class-string> $classes
     *
     * @return \WeakMap<object, null>
     */
    public function objects(array $classes): \WeakMap
    {
        throw new \Error('Listing objects needs the Rapira extension');
    }

    /**
     * @see \rapira_finish_request()
     */
    public function finishRequest(): bool
    {
        return false;
    }
}
