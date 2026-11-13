<?php

declare(strict_types=1);

namespace Rapira\Queue;

use Rapira\Dispatcher;

/**
 * The queue plugin's dispatcher. Obtain it from {@see \Rapira\get_dispatcher()} when the worker's pool
 * consumes pipelines of the `queue` section of `rapira.toml`.
 *
 * Whatever the broker, the host owns its mechanics — acknowledgement, lease renewal, offset commits,
 * retry scheduling, dead-lettering — and the worker answers each delivery with one of three verbs.
 *
 * ```php
 * $queue = \Rapira\get_dispatcher();
 * assert($queue instanceof \Rapira\Queue\QueueDispatcher);
 *
 * $router = new Router($container);
 * foreach ($queue->getPipelines() as $pipeline) {
 *     $router->bind($pipeline); // throws at boot when nothing handles it
 * }
 *
 * try {
 *     while (true) {
 *         $delivery = $queue->receive();
 *         $message = $delivery->getMessage();
 *         $context = $delivery->getContext();
 *         try {
 *             $router->route($message, $context)->handle($message, $context);
 *             $settle = $delivery->complete(...);
 *         } catch (Reject $e) {
 *             $settle = fn() => $delivery->fail(new \Rapira\Queue\Failure($e->getMessage()));
 *         } catch (RetryLater $e) {
 *             $settle = fn() => $delivery->retry($e->delay);
 *         } catch (\Throwable) {
 *             $settle = $delivery->retry(...);
 *         }
 *         try {
 *             $settle();
 *         } catch (\Rapira\Exception\WorkDiscardedException) {
 *             // the host closed it first — lease lost, deadline passed
 *         }
 *     }
 * } catch (\Rapira\Exception\ClosedException) {
 *     // drained
 * }
 * ```
 */
interface QueueDispatcher extends Dispatcher
{
    /**
     * Take a delivery if one is available right now. Never blocks.
     *
     * @return Delivery|null Null means nothing is available at this moment; the queue may fill again.
     * @throws \Rapira\Exception\ClosedException No more deliveries will arrive for this app instance.
     */
    public function tryReceive(): ?Delivery;

    /**
     * Wait up to $timeout for the next delivery, with {@see Dispatcher::receive()}'s waiting semantics:
     * the fiber suspends, not the thread; outside a fiber the process blocks.
     *
     * On an ordered pipeline a message is never handed out while its predecessor — under the same key,
     * in the same partition, or at all — is held unfinalized by any worker of this host.
     *
     * @param int<-1, max> $timeout Microseconds to wait; -1 waits indefinitely, 0 does not wait at all.
     * @throws \Rapira\Exception\TimeoutException No delivery became available within $timeout.
     * @throws \Rapira\Exception\ClosedException No more deliveries will arrive for this app instance.
     */
    public function receive(int $timeout = -1): Delivery;

    /**
     * Live plugin counters. Observability only — never a control-flow source.
     */
    public function getInfo(): QueueDispatcherInfo;

    /**
     * The pipelines this pool consumes, resolved from `rapira.toml` at boot. Every delivery's
     * {@see Delivery\Context::$pipeline} names one of them.
     *
     * @return list<PipelineInfo>
     */
    public function getPipelines(): array;
}
