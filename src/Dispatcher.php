<?php

declare(strict_types=1);

namespace Rapira;

/**
 * @template-covariant THandler of object
 */
interface Dispatcher extends PluginInterface
{
    /**
     * Fetch a handler for a next task to be executed.
     *
     * The result of the task processing is returned by the handler methods.
     *
     * @param int<-1, max> $blockTtl Time to block waiting for a handler to be available, in microseconds.
     *        - 0 means "don't block" (return immediately if no handler is available).
     *        - -1 means "block indefinitely".
     *
     * @return THandler|null Returns {@see null} in case of timeout or the dispatcher is closed.
     */
    public function fetchTask(int $blockTtl = -1): ?object;

    /**
     * Check if the dispatcher is still alive and able to provide task handlers.
     */
    public function isAlive(): bool;
}
