<?php

declare(strict_types=1);

namespace Rapira\Grpc\Call;

use Rapira\Grpc\Call;
use Rapira\Grpc\GrpcDispatcher;
use Rapira\Grpc\Responder\ResponseMetadata;
use Rapira\InetAddress;
use Rapira\UnixAddress;

/**
 * The context of one call, from {@see Call::getContext()}: the request side, whole and immutable.
 * The response side — the {@see ResponseMetadata} accumulator — lives on the call instead, beside the
 * verbs that commit it, so handing this object down grants reading the call, never answering it.
 * Call-scoped: freshly created with the call, dead with its finalization — nothing here survives into
 * the resident worker's next call.
 *
 * Generated service methods keep their plain `Request → Response` signatures; the layer that holds the
 * {@see Call} passes this down however it likes — an ambient accessor, when the SDK wants one, is the
 * SDK's to build.
 */
final readonly class Context
{
    /**
     * @param non-empty-string $method Full method name, `package.Service/Method` —
     *        `billing.v1.InvoiceService/CreateInvoice`. Resolves against
     *        {@see GrpcDispatcher::getServices()}.
     * @param Metadata $metadata Request metadata, application keys only: the transport-reserved
     *        namespaces (`grpc-*`, Connect control headers, `content-*`) never appear here — their
     *        facts arrive as $deadline, $protocol and the payload itself.
     * @param float|null $deadline Unix timestamp with microsecond precision after which the outcome is
     *        no longer wanted, parsed by the host from `grpc-timeout` or the Connect equivalent and
     *        clamped per `rapira.toml`. Null when the client named none and no default is configured.
     *        Advisory for budgeting work — skip the cache refill, shrink the batch; the host enforces
     *        it regardless, and {@see Call::isCancelled()} turns true when it passes.
     * @param InetAddress|UnixAddress $remote The peer's end of the connection. Deciding whether to
     *        trust it, and which forwarding header supersedes it, is the framework's business.
     * @param Protocol $protocol What the client actually spoke. Diagnostic — a log field, never a
     *        branching invite: the host has already normalized everything the protocols do differently.
     * @param float $receivedAt Unix timestamp with microsecond precision, taken when the host accepted
     *        the call — before it queued and before this worker took it.
     */
    public function __construct(
        public string $method,
        public Metadata $metadata,
        public ?float $deadline,
        public InetAddress|UnixAddress $remote,
        public Protocol $protocol,
        public float $receivedAt,
    ) {}
}
