<?php

declare(strict_types=1);

namespace Rapira\Grpc;

/**
 * One method of a {@see ServiceInfo}. What an adapter needs to bind it: which generated classes decode
 * the messages, and which {@see Call} type — request and response shapes both — the method arrives as.
 */
final readonly class MethodInfo
{
    /**
     * @param non-empty-string $name Bare method name, `CreateInvoice`; the full name on
     *        {@see Call\Context::$method} is `{ServiceInfo::$name}/{$name}`.
     * @param non-empty-string $inputType Fully qualified message name, `billing.v1.CreateInvoiceRequest`.
     * @param non-empty-string $outputType Fully qualified message name, `billing.v1.Invoice`.
     */
    public function __construct(
        public string $name,
        public string $inputType,
        public string $outputType,
        public MethodKind $kind,
    ) {}
}
