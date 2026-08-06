<?php

declare(strict_types=1);

namespace Rapira\Grpc;

use Rapira\Dispatcher;

/**
 * The gRPC plugin's dispatcher. Obtain it from {@see \Rapira\get_dispatcher()} when the worker serves
 * the `grpc` section of `rapira.toml`.
 *
 * Whatever protocol a call arrived over, every request message crosses the boundary as the canonical
 * binary-protobuf encoding of the method's input message and the response crosses back the same way;
 * framing, compression, deadline parsing and per-protocol error encoding never reach the worker.
 *
 * ```php
 * $grpc = \Rapira\get_dispatcher();
 * assert($grpc instanceof \Rapira\Grpc\GrpcDispatcher);
 *
 * $router = new ServiceRouter();
 * foreach ($grpc->getServices() as $service) {
 *     $router->register($service, $container->get($service->name));
 * }
 *
 * try {
 *     while (true) {
 *         $call = $grpc->receive();
 *         try {
 *             $router->dispatch($call);
 *         } catch (\Rapira\Grpc\Exception\GrpcException $e) {
 *             $call->fail($e->status);
 *         }
 *     }
 * } catch (\Rapira\Exception\ClosedException) {
 *     // drained
 * }
 * ```
 */
interface GrpcDispatcher extends Dispatcher
{
    /**
     * Take a call if one is available right now. Never blocks.
     *
     * @return (Call&Responder)|null Null means nothing is available at this moment; the queue may
     *         fill again.
     * @throws \Rapira\Exception\ClosedException No more calls will ever arrive.
     */
    public function tryReceive(): (Call&Responder)|null;

    /**
     * Wait up to $timeout for the next call, with {@see Dispatcher::receive()}'s waiting semantics:
     * the fiber suspends, not the thread; outside a fiber the process blocks.
     *
     * @param int<-1, max> $timeout Microseconds to wait; -1 waits indefinitely, 0 does not wait at all.
     * @throws \Rapira\Exception\TimeoutException No call became available within $timeout.
     * @throws \Rapira\Exception\ClosedException No more calls will ever arrive.
     */
    public function receive(int $timeout = -1): Call&Responder;

    /**
     * Live plugin counters. Observability only — never a control-flow source.
     */
    public function getInfo(): GrpcDispatcherInfo;

    /**
     * The services this plugin dispatches: the `services` entries of the `grpc` TOML section,
     * resolved against the host's descriptor registry at boot.
     *
     * @return list<ServiceInfo>
     */
    public function getServices(): array;
}
