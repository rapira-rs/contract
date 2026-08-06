<?php

declare(strict_types=1);

namespace Rapira\Grpc;

/**
 * One service this plugin dispatches, from {@see GrpcDispatcher::getServices()}: the plugin's side of
 * the contract, resolved from the host's descriptor registry at boot. The adapter's side is a local
 * implementation bound to {@see self::$name}.
 */
final readonly class ServiceInfo
{
    /**
     * @param non-empty-string $name Fully qualified: `billing.v1.InvoiceService`.
     * @param list<MethodInfo> $methods In descriptor order.
     */
    public function __construct(
        public string $name,
        public array $methods,
    ) {}
}
