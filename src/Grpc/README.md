# Rapira contract — gRPC

The shared core — `Dispatcher`, `Work`, the address types, the rules every plugin obeys — lives in
the [package README](../../README.md); this file holds only what is gRPC's own.

Host-side the plugin is built on ConnectRPC rather than a native gRPC stack:
one registration serves native gRPC, binary gRPC-Web and Connect — proto and JSON — selected per
request from `Content-Type`, and none of that reaches PHP. Every request message crosses the boundary
as the canonical binary-protobuf encoding of the method's input message — Connect-JSON is one
descriptor-driven transcode at the edge — and the response crosses back the same way. Framing,
per-message compression, `grpc-timeout` parsing and per-protocol error encoding are the host's job.
Dispatch is descriptor-driven: `.proto` sources compile at boot, the `[grpc].services` entries resolve
against them or the boot fails, and adding a PHP service method never rebuilds the host.

```php
namespace Rapira\Grpc;

interface GrpcDispatcher extends \Rapira\Dispatcher
{
    public function tryReceive(): UnaryCall|ServerStreamingCall|ClientStreamingCall|BidiStreamingCall|null;

    public function receive(int $timeout = -1): UnaryCall|ServerStreamingCall|ClientStreamingCall|BidiStreamingCall;

    public function getInfo(): GrpcDispatcherInfo;

    /** What this plugin dispatches, resolved from the descriptor registry at boot. The adapter binds
     *  one local implementation per entry at its own boot, so a missing one fails the worker there,
     *  by name — never as an `Unimplemented` surprise on the first live call.
     *  @return list<ServiceInfo> */
    public function getServices(): array;
}

/** The reading side of a call. */
interface Call extends \Rapira\Work
{
    public function getContext(): Call\Context;
}

/** The answering side. Failing lives here because every kind fails the same way; the success verb is
 *  per axis, because its shape belongs to the method. */
interface Responder extends \Rapira\Work
{
    public function getResponseMetadata(): Responder\ResponseMetadata;

    public function fail(Status $status): void;
}

/** google.rpc.Status: the triple an RPC fails with. Everything rich errors express fits in $details. */
final readonly class Status
{
    public function __construct(
        public StatusCode $code,                 // int-backed enum of the 16 error codes; no Ok case
        public string $message = '',
        public array $details = [],              // list<ErrorDetail> — google.protobuf.Any pairs
    ) {}
}

/** Multivalued, keys case-insensitive, `-bin` values arriving as raw bytes — not array<string, string>.
 *  Both sides hand it out: Call\Context::$metadata, and the accumulator's headers()/trailers() snapshots. */
final readonly class Metadata implements \Countable, \IteratorAggregate
{
    /** @param array<lowercase-string&non-empty-string, list<string>> $entries */
    public function __construct(public array $entries = []) {}
}

enum MethodKind: string
{
    case Unary = 'unary';
    case ServerStreaming = 'server-streaming';
    case ClientStreaming = 'client-streaming';
    case BidiStreaming = 'bidi-streaming';

    public function isStreamingRequest(): bool {}
    public function isStreamingResponse(): bool {}
}
```

Each root forks into its two axes — siblings of their roots in the plugin namespace — a method's
calls implement the pair its `.proto` fixed, and the four kinds name the points of the product:

```php
namespace Rapira\Grpc;

/** Unary and ServerStreaming methods: the one request message, in hand before dispatch. */
interface UnaryRequest extends Call
{
    public function getMessage(): string;
}

/** ClientStreaming and BidiStreaming methods: handed out on the first message, the rest arriving. */
interface StreamingRequest extends Call
{
    public function getMessages(): Call\MessageStream;
}

/** Unary and ClientStreaming methods: one message finishes the call. */
interface UnaryResponder extends Responder
{
    public function respond(string $message): void;
}

/** ServerStreaming and BidiStreaming methods: a drained generator finishes the call. */
interface StreamingResponder extends Responder
{
    /** @param \Generator<int, string> $messages */
    public function respond(\Generator $messages): void;
}

/** The four kinds, named: each extends its two axes and adds nothing — one instanceof settles both. */
interface UnaryCall extends UnaryRequest, UnaryResponder {}
interface ServerStreamingCall extends UnaryRequest, StreamingResponder {}
interface ClientStreamingCall extends StreamingRequest, UnaryResponder {}
interface BidiStreamingCall extends StreamingRequest, StreamingResponder {}
```

The shapes only a root hands out live under its name:

```php
namespace Rapira\Grpc\Call;

/** From Call::getContext(): the request side, whole and immutable. */
final readonly class Context
{
    public function __construct(
        public string $method,                   // "billing.v1.InvoiceService/CreateInvoice"
        public \Rapira\Grpc\Metadata $metadata,  // application keys only; grpc-*, content-* never appear
        public ?float $deadline,                 // unix timestamp; null when none. Advisory — the host enforces it
        public InetAddress|UnixAddress $remote,  // the same union HTTP puts on Request::$remote
        public ?Tls $tls,                        // the same shape HTTP puts on Request::$tls; its cert fields are the mTLS identity
        public Protocol $protocol,               // grpc | grpc-web | connect — a log field, never a branch
        public float $receivedAt,
    ) {}
}

/** One forward pass over an inbound stream: Dispatcher vocabulary at message grain. */
interface MessageStream extends \IteratorAggregate
{
    public function next(int $timeout = -1): string; // TimeoutException; ClosedException = half-close
    public function tryNext(): ?string;              // null = nothing at this moment
    public function getIterator(): \Traversable;     // next(-1) as an iterable, ends at half-close
}

namespace Rapira\Grpc\Responder;

/** Mutable per-call accumulator: addHeader()/addTrailer() and their -bin twins. */
interface ResponseMetadata {}
```

The adapter's whole dispatch is two binary questions:

```php
$out = $call instanceof StreamingRequest
    ? $service->handleStream($call->getMessages())
    : $service->handle($call->getMessage());

$call instanceof StreamingResponder
    ? $call->respond($encodeEach($out))
    : $call->respond($out->serializeToString());
```

- The split into `Call` and `Responder` is a privilege ladder, climbed by type: `Context` grants
  reading the data, `Call` adds the `Work` facts, `Responder` answers without reading, and `receive()`
  hands out one of the four kinds — the whole unit. Nothing ambient bypasses the ladder; the table
  below has the reasoning.
- One axis per fact, engine-checked. The response shape is the method's fact, fixed in `.proto`, so
  `respond(string)` and `respond(\Generator)` are two interfaces rather than one union signature
  policed at runtime. An adapter asks two binary `instanceof` questions, or takes the kind whole:
  the four kind interfaces name the points of the product, so `receive()`'s signature is a plain
  union rather than tribal knowledge about which intersections exist. `MethodKind` is the same four
  names before any call exists — at boot, binding services — and projects onto the axes with
  `isStreamingRequest()`/`isStreamingResponse()`, so nobody unpacks them by hand.
- A streaming response is a drained generator: `respond()` returns only when the stream terminates.
  The worker is single-threaded, so the host pumps the generator only while PHP is inside the call;
  backpressure is the generator simply not resumed while the transport's window is closed. Running to
  completion means `OK`; a throwable escaping the generator escapes `respond()` unchanged with the
  call left unfinalized, so the adapter's one catch — `$call->fail($e->status)` — serves unary and
  streaming alike, and a wrapping generator that catches inside the loop may even keep the stream
  alive. The host closing the call — deadline, client gone, drain — is `WorkDiscardedException`
  thrown into the generator at its yield, never a destroy: catch it to salvage progress or let it
  fly, `finally` runs by ordinary unwinding either way, so cancellation needs no token API.
- An inbound stream speaks the dispatcher's vocabulary at message grain: `next($timeout)` waits the
  way `receive()` waits, `tryNext()` polls the way `tryReceive()` polls, and the client's half-close —
  the normal end of every inbound stream — is `ClosedException`, so the message loop is the worker
  loop's shape one level down. Liveness is spelled at the pull, race-free: a message, null and
  `ClosedException` are the three answers. Not pulling is the flow control: the host stops reading the
  client while nothing pulls, so there is no unbounded buffer to overrun. The host closing the call
  mid-wait — deadline, drain, a client gone without half-closing — is `WorkDiscardedException`. The
  stream is also the package's one `iterable`: `getIterator()` — a view over the same shared cursor,
  `next(-1)` to the half-close — is contract because being iterable is a type-level fact no SDK
  wrapper can add, so a `stream Chunk` parameter typed `iterable` takes the stream itself.
- Bidi is composition, not a feature: a response generator that reads `getMessages()` between yields —
  a nested native wait on the same fiber. PHP is embedded in the host process, so no worker wire
  protocol exists to extend for it: each call owns its inbound queue host-side, and routing a message
  is enqueueing it where it is already addressed.
- Responding while a streaming request is still arriving is legal and final — gRPC lets a server
  answer before the client half-closes: messages not yet pulled are discarded and the host tells the
  client to stop sending.
- Response metadata has one home, the mutable accumulator HTTP refused — and the refusal does not
  transfer. An HTTP head commits once and whole, so a bag there is intermediate state moved across the
  boundary; a gRPC call commits twice — headers at the stream's first yield, trailers at its
  termination — and the trailers accumulate while `respond()` is still draining, unreachable as any
  finalizer parameter. Snapshots happen on success and failure alike. The accumulator rejects the
  reserved transport namespaces (`grpc-*`, `content-*`, Connect control headers), and the `-bin`
  discipline is spelled by method: `addBinaryHeader()` requires the suffix, `addHeader()` rejects it,
  so the name's promise and the value's kind can never disagree.
- A status is data, the exception a thin thrower over it — the split grpc-go and grpc-java draw.
  `fail()` takes the `google.rpc.Status` triple, and the host encodes it once per protocol:
  `grpc-status` trailers for gRPC, the trailer frame for gRPC-Web, an HTTP status plus error JSON for
  Connect. The host interprets no exception: any `Throwable` escaping the worker — `GrpcException`
  included — is a bug, not a status: the script fatals, the host answers a sanitized `INTERNAL` —
  trace logged server-side, message withheld — and recycles the worker per pool policy. An error
  status reaches the wire through `fail()` and nowhere else.

## Exceptions

`Grpc\Exception\GrpcException` the host never catches — no exception is interpreted across the
boundary: one escaping a response generator escapes `respond()` unchanged, one escaping the worker is
a bug like any other throwable. The base class is contract so that every SDK and library throws the
same spelling and one adapter catch serves every method kind; its curated subclasses stay SDK
vocabulary. It carries its `Status` as
`$status`, because `\Exception::$code` already exists as an untyped `int` and cannot be redeclared.
`Grpc\Exception\HeadersAlreadyCommittedError` is `HeadAlreadyWrittenError`'s fact in gRPC spelling:
the headers left with the stream's first yield, and only trailers stay open after it.

## Not in the contract

| Omitted | Why |
|---|---|
| `Grpc\call_context()`, an ambient accessor for the call's context | between `receive()` and finalization the host cannot see which held unit the running code serves — `tryReceive()` legally batches several onto one fiber, and processing is plain userland with no dispatch boundary the engine observes — so any host-installed slot (per process, per fiber, even a per-fiber stack) silently answers with a *different* call's deadline and metadata under a reordered batch. The SDK chooses the execution discipline, so the SDK owns the mapping: a static in a one-at-a-time loop, a `Fiber`-keyed map, a `WeakMap` from the decoded request message. The contract's spelling is `Call::getContext()`, where the type binds context to call and no order of processing can shuffle it |
| a union `respond(string\|\Generator)` on one call type | the response shape is the method's fact, fixed in `.proto` — Connect even frames unary and streaming responses differently — so the union carries as a runtime check what the axis interfaces carry as a type |
| `StatusCode::Ok` | `fail()` is the code's only consumer, a successful call is its type's `respond()`, and a type that cannot spell "failed with OK" is worth one missing case |
| curated `GrpcException` subclasses — `NotFoundException`, `InvalidArgumentException`, … | one `parent::__construct()` call each, so they are SDK vocabulary; the base class is contract only so that every SDK and library throws one spelling for one adapter catch |
| `Grpc\Call\Context::timeRemaining()` | `$deadline - microtime(true)` |
| `MethodInfo::$fullName` | `ServiceInfo::$name . '/' . $name` |
| `isAlive()` / `getStatus()` on `MessageStream` | liveness is spelled at the pull: `tryNext()` answers open-with-a-message, open-and-empty or ended without blocking, and `isCancelled()` is the checkpoint. A status checked before the verb is stale by the time the verb runs |
| queue depth on `MessageStream` | the buffer is the transport's flow-control window, sized in bytes, so a message count is not a fact the host owns; the batch case is a `tryNext()` loop, exact where a count races |
| `Metadata::has()` | `values($k) !== []`, computed in place |
| per-message metadata on stream messages | gRPC has none, so there is no envelope to model — a yield is bytes, a step is bytes |
| a cancellation token for streams | closure is thrown into the response generator as `WorkDiscardedException`, so `catch` and `finally` are the structural hooks; `isCancelled()` covers checkpoints |
