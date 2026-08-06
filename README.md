# rapira/contract

PHP-side contract for [Rapira](https://github.com/rapira-rs), a PHP application server written in Rust.
PHP is embedded in the server process — no FastCGI, no sockets, no serialization. This package declares the
types that boundary speaks; the extension provides the objects.

Requires PHP 8.4 — the extension's floor; the stubs themselves use nothing newer than 8.2. Execution
modes form a ladder: `Classic` runs a script per request and has no dispatcher, `SAPI Worker` is a
long-lived process pulling units of work through this contract, `Async Worker` runs several units
concurrently on fibers. Everything below lives on the worker rungs.

## Contract

```php
namespace Rapira;

/** Throws Exception\NotInWorkerModeError outside worker mode. Same instance for the life of the process. */
function get_dispatcher(): Dispatcher {}

/** Version of the running Rapira server. */
function get_version(): string {}

/** Queued to the host under the `app` target. Never blocks, never throws. */
function log(string $message, LogLevel $level = LogLevel::Info, array $context = []): void {}

/** No backing values; a PSR-3 bridge squashes eight levels into these five. */
enum LogLevel { case Error; case Warning; case Info; case Debug; case Trace; }

interface Dispatcher
{
    /** Plugin identity: "grpc", "jobs", "http"... */
    public function name(): string;

    /**
     * Never waits.
     *
     * @return Work|null Null means nothing available at this moment; the queue may fill again.
     * @throws Exception\ClosedException No more work will ever arrive.
     */
    public function tryReceive(): ?Work;

    /**
     * @param int<-1, max> $timeout Microseconds to wait; -1 waits indefinitely, 0 not at all.
     * @throws Exception\TimeoutException No work became available within $timeout.
     * @throws Exception\ClosedException No more work will ever arrive.
     */
    public function receive(int $timeout = -1): Work;

    /** Live plugin counters. Observability only — never a control-flow source. */
    public function getInfo(): DispatcherInfo;
}

interface Work
{
    public function isFinalized(): bool;

    /** Is the result still wanted? False on deadline, disconnected client, lost lease. */
    public function isCancelled(): bool;
}

/** Immutable snapshot: all values captured at creation, so the counters are consistent. */
interface DispatcherInfo
{
    /** @return int<0, max> Units the plugin holds pending, not yet handed to any worker. */
    public function pendingCount(): int;

    /** @return int<0, max> Units handed to this worker and not yet finalized. */
    public function activeCount(): int;
}

/** The two arms of an address: a port exists exactly when the endpoint is an IP one. Shared, since
 *  every plugin that names a peer names it the same way. */
final readonly class InetAddress
{
    public function __construct(
        public string $ip,
        public int $port,      // int<1, 65535> — the zero sentinel is gone with the union
    ) {}
}

final readonly class UnixAddress
{
    public function __construct(
        public ?string $path,  // null: an unnamed peer, the usual case for a connecting client
    ) {}
}
```

Plugins narrow `receive()` natively and add their own finalization verbs:

```php
namespace Rapira\Http;

use Rapira\InetAddress;
use Rapira\UnixAddress;

interface HttpDispatcher extends \Rapira\Dispatcher
{
    public function tryReceive(): ?Exchange;

    public function receive(int $timeout = -1): Exchange;

    public function getInfo(): HttpDispatcherInfo;
}

/** One request/response exchange: the request data plus the verbs that answer it. */
interface Exchange extends \Rapira\Work
{
    public function getRequest(): Request;

    /** Writes a head. `1xx` is interim: on the wire at once, repeatable, advisory. A final head commits
     *  once, and committing is not sending — the bytes coalesce with the first body chunk. */
    public function writeHead(int $status, array $headers = []): void;

    /** Reaches the wire. `$eos` ends the response and finalizes the exchange. */
    public function writeBody(string $content, bool $eos = true): void;

    /** The host opens and streams the file, so PHP never holds the bytes and the worker is not held by
     *  the download. Nothing else: no `content-type`, no `etag`, no `Range` parsing. */
    public function sendFile(string $path, int $offset = 0, ?int $length = null, bool $eos = true): void;

    /** The other ending: a trailer section. Nothing the client needs — intermediaries discard trailers. */
    public function writeTrailers(array $trailers): void;

    /** Force a committed head out before any body exists, giving up `content-length`. */
    public function flush(): void;
}

final readonly class Request
{
    public function __construct(
        public string $method,                   // byte-for-byte, case-sensitive (RFC 9110 §9.1)
        public string $uri,                      // absolute, synthesized: listener scheme + $authority
        public string $target,                   // request-target byte-for-byte — what SigV4 signed; :path on h2/h3
        public ?string $authority,               // :authority or Host, byte-for-byte; null when none was named
        public string $protocol,                 // HTTP/1.1, HTTP/2, HTTP/3
        public array $headers,                   // as received, no pseudo-headers, not normalized
        public string|Multipart $body,           // bytes as received — or the parsed form; never both, by type
        public InetAddress|UnixAddress $remote,  // the peer's end; a unix peer is usually unnamed
        public InetAddress|UnixAddress $server,  // which socket took the call, not the Host header
        public ?Tls $tls,                        // null on a plaintext listener
        public float $receivedAt,                // when the host accepted it, not when the worker took it
    ) {}
}

/** What $body is when the host parsed a multipart/form-data upload as it streamed in. */
final readonly class Multipart
{
    public function __construct(
        public array $fields,  // list<FormField>: name, value, part headers — parts without a filename
        public array $files,   // list<UploadedFile>: name, clientFilename, clientMediaType, headers, tmpPath, size
    ) {}
}
```

The unit is an *exchange*, not a handler: in PHP a handler is the thing that does the work (PSR-15), so
`RequestHandler` for the thing being worked on would collide with every framework. `HttpExchange`
(`com.sun.net.httpserver`), `HttpServerExchange` (Undertow) and RFC 9110's own "request/response
exchange" all name this shape the same way.

## Rules

- PHP pulls, the host never pushes. The loop belongs to userland; without it there is no scheduler, no work
  between units, no event loop integration.
- Config flows TOML → host → PHP. PHP discovers what it serves and never parameterizes a plugin.
- One spelling per fact: `null` is "nothing at this moment", `ClosedException` is "no more work, ever",
  `TimeoutException` is "the wait elapsed". No value carries two meanings.
- At capacity `receive()` waits and `tryReceive()` returns null — backpressure, not an error. Same
  behaviour at one unit in flight and at N.
- Waiting suspends the calling fiber, not the thread; outside a fiber it blocks the process, because the
  main context cannot be suspended. Who resumes a suspended fiber is the PHP wrapper's business, never
  this contract's: the contract names its suspension points — `receive()`, a `MessageStream` step, a
  streaming drain — and never names a scheduler.
- Writing commits without promising the wire: a committed head coalesces with the first body chunk, and
  `flush()` trades the computed `content-length` away when the head must arrive first. An interim `1xx` is
  the exception the protocol itself makes and goes out at once. `send*` says the bytes come from somewhere
  other than the caller: `sendFile()` names a path and the host reads it.
- Finalization verbs live on the unit and are per-plugin. `Work` carries only the two facts a generic layer
  cannot compute for itself.
- The response is written incrementally, and the SDK builds the atomic conveniences — a PSR-7 stream,
  `respond($response)` — because incremental composes into atomic and not the other way round.
- The request is deliberately not symmetric with it: the host collects the whole body before dispatching and
  hands it over as one string, so no PHP worker is held open by a slow uploader and `Expect: 100-continue`,
  `413` and malformed framing never reach PHP. The cost is answering `401` before the upload arrives —
  bandwidth, not worker time.
- `multipart/form-data` is parsed by the host as the upload streams in: a part with a `filename` spools
  to disk and arrives as `UploadedFile`, a part without stays in memory as `FormField`, and `$body` is
  the `Multipart` holding both. The union `string|Multipart` is the point: "raw or parsed, never both"
  is enforced by the engine, not documented. Both classes keep their part's header section, because a
  part is more than its value: an API client marks a field `content-type: application/json`.
  `UploadedFile` also lifts `$clientMediaType` and `$size`, both derivable from `$headers` and
  `$tmpPath` — but the PSR-7 hydration derives them for every file of every request, so "could the SDK
  compute it" yields where the SDK must compute it every time and the host already holds both facts.
  Limits live in `rapira.toml` (`413` past them); a malformed body — bad framing, a duplicated `content-disposition`
  or parameter — is `400` before dispatch, so no two parsers in the chain can disagree about it. Spooled
  files live until the exchange finalizes: `rename()` keeps one, the host deletes the rest. The pair
  completes `sendFile()`'s symmetry: the host spools an upload and PHP moves it, PHP names a path and
  the host sends it.
- `$authority` is the raw fact and `$uri` the resolved one. On h2 the authority travels as `:authority`,
  which is not a field (RFC 9113 §8.3) and never appears in `$headers`; without the field it would survive
  only inside the synthesized `$uri`, indistinguishable from the listener fallback. Go promotes `Host` into
  a field and deletes the header from the map — here the promotion is additive: nothing enters or leaves
  `$headers`.
- Addresses are the union `InetAddress|UnixAddress`, mirroring Pingora's own `SocketAddr`: a unix
  listener has no IP and its connecting peer usually no name at all, so "a port exists" is a fact the
  type states — not a zero sentinel carrying two meanings. They live in `Rapira\`: HTTP and gRPC both
  put them on their request shapes, and what plugins share, the root holds.
- Each plugin owns a first-level namespace: `Rapira\Http`, `Rapira\Grpc`, later `Rapira\Jobs`. `Rapira\`
  holds only what they share — `Dispatcher`, `Work`, `DispatcherInfo`, `LogLevel`, the address types,
  the functions — and `Rapira\Exception\` only the exceptions more than one plugin can throw. A plugin's
  own live the same way, in its own `Exception\` sub-namespace — `Http\Exception\HeadAlreadyWrittenError` —
  one rule for where a throwable lives, whichever surface throws it. A family of variants lives under
  its root's name the same way: `Grpc\Call\StreamingRequest` extends `Grpc\Call`, the directory
  repeating the hierarchy. So do the shapes only that root hands out — `Grpc\Call\Context` exists
  through `Call::getContext()` and nothing else — while what worker code constructs itself, `Status`
  and the boot-time descriptors, stays at the plugin root: the directory answers who gives you the
  object.
- Unfinalized units are the host's problem: it fails them and recycles the worker per pool policy.
- Cancellation is cooperative. VM interrupts are a pool watchdog, not routine cancellation, and cannot fire
  while PHP is inside a blocking native call.
- Interface members are methods, not hooked properties — internal classes cannot declare hooks, and the stub
  generator has no syntax for them.
- The consumer is Rapira's SDK, not application code. Test for any addition: could the SDK compute it
  itself? Then it does not belong here.
- Interfaces state behaviour, not the reasoning behind it. Why something is shaped the way it is, or absent,
  is recorded here.

## Framing

The host frames the response and the worker states what it knows. `transfer-encoding` and the other
hop-by-hop fields are dropped from a head: chunked is never asked for, it is chosen.

- **A `content-length` in the head is honoured**, and then enforced: the write that would exceed it raises
  `Http\Exception\ContentLengthExceededError`, and the surplus is never sent — on a reused connection it
  would be read as the start of the next response. Ending the response short of the declaration instead
  leaves a promise unkept, so the host closes the connection rather than reuse it.
- **No `content-length`, and the response ends on its first body write** — one `writeBody($all)` or one
  `sendFile($path)` — so the host computes the length. The head is still buffered at that point, which is
  why size is no object here.
- **No `content-length` and more than one write, or a head already forced out by `flush()`** — HTTP/1.1
  chunked, HTTP/2 and later plain DATA frames ending on `END_STREAM`. Never close-delimited, which kills
  keep-alive; HTTP/1.0, having no chunked, is the one client that still gets it.
- **`204`, `304`, and any response to `HEAD`** carry neither body nor trailer section, whatever the head
  says (RFC 9112 §6.3). Body and trailer writes are accepted and dropped, so a handler answers `HEAD` with
  its `GET` code path. No `content-length` is synthesised from what was dropped: RFC 9110 §8.6 forbids one
  on `204` and permits it on `HEAD` or `304` only when it equals what a `200` would have sent — which, the
  body having been in hand, it can.
- **`1xx` heads carry no framing fields at all.**

### Content coding

`Content-Encoding` belongs to the representation, not to the transfer (RFC 9110 §8.4), so `content-length`,
`etag`, `Repr-Digest` and byte ranges all describe the *coded* bytes. A host-side compression middleware
follows from that sentence:

- It leaves alone any response already carrying `content-encoding` — the worker coded it, those bytes are
  the representation. That is also how to serve a large asset: `sendFile('asset.br')` with
  `content-encoding: br` is byte-stable, so a strong `etag` and byte ranges both stay honest.
- It never codes a `206` or a `sendFile()` slice: `content-range` counts offsets in the representation the
  handler sliced, and coding afterwards leaves the field and the body describing different things.
- When it does code, it computes `content-length` itself, adds `Vary: Accept-Encoding`, drops
  `Accept-Ranges`, and weakens or removes the worker's `etag`: coding on the fly is not byte-stable, a
  strong validator would be a lie, and `If-Range` accepts nothing weaker (RFC 9110 §13.1.5). nginx draws
  the same line — `gzip` gives up ranges, `gzip_static` keeps them.
- It honours `cache-control: no-transform` (RFC 9111 §5.2.2.6). Not a nicety: compressing
  attacker-controlled input next to a secret leaks the secret through the response length (BREACH).
- On a streamed response it sync-flushes the compressor at every chunk that does not end the response, or
  leaves that response alone — a compressor holding bytes back turns `flush()` into a lie, and is how
  `text/event-stream` ends up silent.

## gRPC

The second dispatcher surface. Host-side it is built on ConnectRPC rather than a native gRPC stack:
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
    public function tryReceive(): (Call&Responder)|null;

    public function receive(int $timeout = -1): Call&Responder;

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

Each root forks into its two axes, a method's calls implement the pair its `.proto` fixed, and the
shapes a root hands out live under its name:

```php
namespace Rapira\Grpc\Call;

/** Unary and ServerStreaming methods: the one request message, in hand before dispatch. */
interface UnaryRequest extends \Rapira\Grpc\Call
{
    public function getMessage(): string;
}

/** ClientStreaming and BidiStreaming methods: handed out on the first message, the rest arriving. */
interface StreamingRequest extends \Rapira\Grpc\Call
{
    public function getMessages(): MessageStream;
}

/** From Call::getContext(): the request side, whole and immutable. */
final readonly class Context
{
    public function __construct(
        public string $method,                   // "billing.v1.InvoiceService/CreateInvoice"
        public Metadata $metadata,               // application keys only; grpc-*, content-* never appear
        public ?float $deadline,                 // unix timestamp; null when none. Advisory — the host enforces it
        public InetAddress|UnixAddress $remote,  // the same union HTTP puts on Request::$remote
        public Protocol $protocol,               // grpc | grpc-web | connect — a log field, never a branch
        public float $receivedAt,
    ) {}
}

/** Multivalued, keys case-insensitive, `-bin` values arriving as raw bytes — not array<string, string>. */
final class Metadata implements \Countable, \IteratorAggregate {}

/** One forward pass over an inbound stream; iteration ends when the client half-closes. */
final class MessageStream implements \Iterator {}

namespace Rapira\Grpc\Responder;

/** Unary and ClientStreaming methods: one message finishes the call. */
interface UnaryResponse extends \Rapira\Grpc\Responder
{
    public function respond(string $message): void;
}

/** ServerStreaming and BidiStreaming methods: a drained generator finishes the call. */
interface StreamingResponse extends \Rapira\Grpc\Responder
{
    /** @param \Generator<int, string> $messages */
    public function respond(\Generator $messages): void;
}

/** Mutable per-call accumulator: addHeader()/addTrailer() and their -bin twins. */
final class ResponseMetadata {}
```

The adapter's whole dispatch is two binary questions:

```php
$out = $call instanceof Call\StreamingRequest
    ? $service->handleStream($call->getMessages())
    : $service->handle($call->getMessage());

$call instanceof Responder\StreamingResponse
    ? $call->respond($encodeEach($out))
    : $call->respond($out->serializeToString());
```

- The split into `Call` and `Responder` is a privilege ladder, climbed by type: `Context` grants
  reading the data, `Call` adds the `Work` facts, `Responder` answers without reading, and `receive()`
  hands out the intersection — the whole unit. Nothing ambient bypasses the ladder; the table below
  has the reasoning.
- One axis per fact, engine-checked. The response shape is the method's fact, fixed in `.proto`, so
  `respond(string)` and `respond(\Generator)` are two interfaces rather than one union signature
  policed at runtime. An adapter asks two binary `instanceof` questions and never branches four ways.
  `MethodKind` is the same pair of facts before any call exists — at boot, binding services — and
  projects onto the axes with `isStreamingRequest()`/`isStreamingResponse()`, so nobody unpacks the
  four names by hand.
- A streaming response is a drained generator: `respond()` returns only when the stream terminates.
  The worker is single-threaded, so the host pumps the generator only while PHP is inside the call;
  backpressure is the generator simply not resumed while the transport's window is closed. Running to
  completion means `OK`; a `GrpcException` escaping mid-stream is caught at the drain and becomes the
  terminal status; a client that went away destroys the generator — `finally` blocks run, `respond()`
  returns normally — so ordinary cancellation needs no token API.
- An inbound stream is one forward pass of an iterator. Its end is the client's half-close, spelled as
  the end of iteration and never as an exception, because every stream ends. A step waits exactly as
  `receive()` waits, and not pulling is the flow control: the host stops reading the client while
  nothing pulls, so there is no unbounded buffer to overrun. The host closing the call mid-wait —
  deadline, drain, a client gone without half-closing — is `WorkDiscardedException`.
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
  Connect. Anything *not* a `GrpcException` escaping the worker is a bug, not a status: the script
  fatals, the host answers a sanitized `INTERNAL` — trace logged server-side, message withheld — and
  recycles the worker per pool policy.

## Exceptions

`Rapira\Exception\TimeoutException` and `Rapira\Exception\ClosedException` are both caught routinely — the
first by a loop doing periodic chores, the second as the loop's exit — so both need types. They extend the
SPL class that fits (`\RuntimeException`) and implement the `Rapira\Exception\RapiraThrowable` marker, so
"anything from Rapira" is catchable without forcing every error into one hierarchy. The marker is named
for what it spans: the `\Error` classes below implement it too, which makes it a supervisor's catch at
the top of the worker, never a handler's.

`AlreadyFinalizedError` extends `\Error` — nobody catches it, the script fatals, the host cleans up. Not
`\LogicException`, which frameworks catch broadly enough to swallow it. The error/exception split is left
to the native hierarchy, so `instanceof \Error` keeps meaning "your code is wrong" and no second marker is
needed for it. `NotInWorkerModeError` is the same shape: a worker script running where no dispatcher
exists is wrong by construction.

`Http\Exception\ContentLengthExceededError` and `Http\Exception\HeadAlreadyWrittenError` are both `\Error`
for the same reason as `AlreadyFinalizedError`: the response is already unsalvageable by the time either is
raised, so there is nothing for a handler to do but fatal. `Http\Exception\HeadNotWrittenError` — a trailer
section with no committed head — is `\Error` on the other test: nothing is written yet, but the code is
wrong however the world turns, since nothing on the way to a trailer section commits a head implicitly.
`Http\Exception\FileNotSendableException` is the case that earns an exception — a correct call the world
failed, raised before `sendFile()` has written anything, so `404` is still on the table.

`WorkDiscardedException` is finalizing a unit the host had already closed — expired deadline, drain, gone
client, lease lost to another worker. The worker broke no rule, so it is a runtime exception and not an
error, and a handler catches it to log the loss. Polling `Work::isCancelled()` at checkpoints avoids
getting there at all.

`Grpc\Exception\GrpcException` is the one throwable the host itself matches on: escaping a streaming
generator, it is caught at the drain and becomes the terminal status, framed per protocol — which is
why the base class is contract while its curated subclasses are not. It carries its `Status` as
`$status`, because `\Exception::$code` already exists as an untyped `int` and cannot be redeclared.
`Grpc\Exception\HeadersAlreadyCommittedError` is `HeadAlreadyWrittenError`'s fact in gRPC spelling:
the headers left with the stream's first yield, and only trailers stay open after it.

## Not in the contract

| Omitted | Why |
|---|---|
| `PluginHandlerConfig`, `create_plugin_handler($config)` | second source of truth for what `rapira.toml` owns |
| `PluginInterface` above `Dispatcher` | a parent interface is extractable later at zero BC cost |
| `@template-covariant` generics | native return-type covariance already does this, engine-checked |
| `isAlive()` | the exit condition needs one source; `while ($d->isAlive())` around a blocking call is wrong by construction |
| `concurrency(): int` | blocking is the backpressure; the effective limit is the min of both sides, unexchanged |
| `inFlight()` as an exit condition | host counts unfinalized units, SDK counts live handlers — different numbers; as a `DispatcherInfo` counter it is fine |
| `LifecycleException` | several units in flight is the normal case, not a violation |
| PSR-7 request objects | pins the extension to a `psr/http-message` major; hydrate in userland |
| `receiveMany()` | latency poison for request traffic; batching is plugin vocabulary |
| `respond(Response)`, a `Response` value object | the SDK builds it on `writeHead()`/`writeBody()`; the reverse is impossible, since an atomic response cannot send a head before the first body byte exists |
| a mutable header bag on the exchange — `setHeader()`, `getHeaders()` | the head has no incremental dimension — it commits at once, so a bag is the same commit with its intermediate state moved across the boundary: two owners for one fact, one native call per header instead of one per response. Go needs `Header()` only because it has no response object; PHP has PSR-7, so the bag lives in userland and arrives as one array |
| `writeStatus(int)` apart from the headers | the status line and the field lines are one section on the wire, and the fields are never known without the status. A status-only response is `writeHead(204)` |
| `string` in place of `list<string>` for a header value | `Request::$headers` arrives as lists and PSR-7's `getHeaders()` returns lists — two spellings for one fact to save two brackets |
| a verb of its own for interim responses — `sendEarlyHints()` | `writeHead()` takes any `1xx` and puts it on the wire at once — one verb per head, as Go and Pingora both do. Go split the verbs because of its shared mutable header map; passing the fields as an argument leaves no map and no rule. `101` counts as a final head, and `100` is the host's answer to `Expect` before a worker ever sees the exchange |
| derived request data (query, cookies, negotiation) | `parse_str()` and friends already do it, per framework conventions |
| backing values on `LogLevel` | the host matches cases, so no string is on the wire — and without `tryFrom()` a PSR-3 bridge cannot half-map: `tryFrom($level) ?? Info` would file every `emergency` under `Info` |
| CN, SAN and issuer on `Tls`, or a field per certificate attribute | fingerprint pinning covers mTLS identity, and Pingora's `SslDigest` exposes nothing more ([pingora#421](https://github.com/cloudflare/pingora/issues/421)). When names are needed, the addition is one `certPem` field with the whole certificate — `openssl_x509_parse()` reads every attribute in userland |
| request trailers | Pingora cannot parse them in either HTTP version (`// TODO: trailer`), so S3-style `x-amz-trailer` checksums are unreachable. A plain added field on `Request` when that changes — the buffered body allows it |
| a live request stream, read while the client is still sending | pins a worker for the length of the upload, so a handful of slow clients idles the pool — the reason nginx defaults to `proxy_request_buffering on`. Also removes the HTTP/1.1 read-write deadlock Go's `EnableFullDuplex` exists to opt into |
| `readBody(): ?string` handing the buffered body over in chunks | bounds what PHP holds, but serves neither case well: a 20 KB JSON body wants `$request->body`, and the 1 GB upload is already a path — `Multipart::$files` |
| `$error` / `UPLOAD_ERR_*` on `UploadedFile` | errors cannot reach PHP — the host answers `413`/`400` first; hydration maps an empty `$clientFilename` to `UPLOAD_ERR_NO_FILE` and everything else to `UPLOAD_ERR_OK` |
| a `moveTo()` verb on `UploadedFile` | `rename()` is that verb, and PHP already has it |
| parsing `multipart/mixed`, `multipart/related` | RFC 7578 dropped nested multipart; anything but `form-data` arrives as the raw `$body` |
| conditional requests, `Range` parsing, `etag` and `content-type` on `sendFile()` | Go's `ServeFile` does all of it and every PHP framework already does too. The verb exists for the one thing userland cannot do — not holding the bytes; a `206` is the handler writing its own `content-range` and passing a slice |
| a stream resource in place of a path on `sendFile()` | a path is the one thing the host can open by itself; a PHP resource may be `php://temp` or a userland wrapper, and reading it means going back through PHP for every chunk — exactly what the verb exists to avoid |
| `Content-Type` sniffing on the first body write | Go guesses from the first 512 bytes because a Go handler may not know; a PHP application does, and a guessed type the browser then trusts is what `nosniff` exists to stop |
| an optional-capability object (`http.ResponseController`) | Go needs one because `ResponseWriter` is a public interface with third-party implementations, so `Flush` arrives by type assertion; `Exchange` has a single implementation, so `flush()` sits on it |
| `Grpc\call_context()`, an ambient accessor for the call's context | between `receive()` and finalization the host cannot see which held unit the running code serves — `tryReceive()` legally batches several onto one fiber, and processing is plain userland with no dispatch boundary the engine observes — so any host-installed slot (per process, per fiber, even a per-fiber stack) silently answers with a *different* call's deadline and metadata under a reordered batch. The SDK chooses the execution discipline, so the SDK owns the mapping: a static in a one-at-a-time loop, a `Fiber`-keyed map, a `WeakMap` from the decoded request message. The contract's spelling is `Call::getContext()`, where the type binds context to call and no order of processing can shuffle it |
| a union `respond(string\|\Generator)` on one call type | the response shape is the method's fact, fixed in `.proto` — Connect even frames unary and streaming responses differently — so the union carries as a runtime check what the axis interfaces carry as a type |
| `StatusCode::Ok` | `fail()` is the code's only consumer, a successful call is its type's `respond()`, and a type that cannot spell "failed with OK" is worth one missing case |
| curated `GrpcException` subclasses — `NotFoundException`, `InvalidArgumentException`, … | one `parent::__construct()` call each, so they are SDK vocabulary; the base class is contract only because the host matches it at the stream drain |
| `Grpc\Call\Context::timeRemaining()` | `$deadline - microtime(true)` |
| `MethodInfo::$fullName` | `ServiceInfo::$name . '/' . $name` |
| timeout and `try` variants on a `MessageStream` step | additive when the first consumer needs periodic chores between messages; `$deadline` and `isCancelled()` cover the known cases |
| per-message metadata on stream messages | gRPC has none, so there is no envelope to model — a yield is bytes, a step is bytes |
| a cancellation token for streams | a gone client destroys the response generator, so `finally` is the structural hook; `isCancelled()` covers checkpoints |

## Open

1. **Deadlines on HTTP.** gRPC picked the spelling: `Grpc\Call\Context::$deadline`, a unix timestamp the
   handler subtracts from — advisory, since the host enforces it regardless. What stays open is HTTP's
   side: whether `Http\Request` grows the same field, and whether a handler may ever *set* a deadline
   (Go's `ResponseController.SetReadDeadline`/`SetWriteDeadline`) rather than read what is left of one.
2. **Non-dispatcher plugins.** A logger richer than `log()` — its own target, its own sink — or a KV
   client is not a stream of work units and needs a second acquisition path, without bringing back
   config objects.
3. **Superglobal hydration** as an explicit call on the unit, so the PSR path does not pay for globals it
   never reads.
4. **Large raw bodies — `SpooledBody`.** Multipart is answered by `Multipart::$files`, but a large `PUT`
   of raw bytes is still bounded by what a worker holds, and the host's body limit belongs below
   `memory_limit`. The shape is decided: a third arm of the body union, `string|Multipart|SpooledBody`,
   where `SpooledBody` carries one `non-empty-string $path` — the host spools past a `rapira.toml`
   threshold, the file lives until the exchange finalizes, `rename()` keeps it. A class rather than a
   path in the string arm, because a path in `$body` would be unreadable as one. It waits for a
   consumer: the threshold and which requests spool are host config surface not worth designing before
   the first real use, and widening a union is cheap only while nobody `match`es it exhaustively.
5. **Which filesystem root `sendFile()` may read from.** The host opens the path, so `open_basedir` does
   not apply and user input passed through names any file the server process can read. A root belongs in
   `rapira.toml`, and it wants to exist before the first traversal rather than after one. Zero-copy is a
   separate and later question: Pingora has no `sendfile(2)` path today, and terminating TLS in process
   rules the syscall out regardless.
6. **`writeTrailers()` is provisional**, pending a team call. For it: RFC 9530 dedicates a worked example
   to `Trailer: Repr-Digest` — a standard spelling for the one case the method serves — and Go, Node,
   Servlet 4.0, Envoy, nginx and HAProxy all implement or pass trailers, as proxying gRPC requires.
   Against: browsers never expose them to JavaScript, and neither PSR-7 nor HttpFoundation has a vocabulary
   for them. Decisively: Pingora's `write_response_trailers` on HTTP/1.1 is a no-op — `// TODO: support
   trailers for h1`, still open on main — so our own host drops them silently there, and the method does
   anything at all only over end-to-end HTTP/2. Dropping it costs one method and no signature.

## References

- [rapira-rs/rapira#38](https://github.com/rapira-rs/rapira/issues/38) — plugin handler API; its
  config-object direction was superseded within its own thread.
- [rapira-rs/rapira#45](https://github.com/rapira-rs/rapira/issues/45) — dispatcher interfaces; origin of
  the pull model and the finalization discipline.
