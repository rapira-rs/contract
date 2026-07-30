<?php

declare(strict_types=1);

namespace Rapira\Http;

use Rapira\Dispatcher;

/**
 * The HTTP plugin's dispatcher. Obtain it from {@see \Rapira\get_dispatcher()} when the worker serves
 * the `http` section of `rapira.toml`.
 *
 * ```php
 * $http = \Rapira\get_dispatcher();
 *
 * try {
 *     while (true) {
 *         $exchange = $http->receive();
 *         $exchange->writeBody($kernel->handle($exchange->getRequest()));
 *     }
 * } catch (\Rapira\Exception\ClosedException) {
 *     // drained
 * }
 * ```
 */
interface HttpDispatcher extends Dispatcher
{
    public function tryReceive(): ?Exchange;

    public function receive(int $timeout = -1): Exchange;

    public function getInfo(): HttpDispatcherInfo;
}
