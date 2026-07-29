# rapira/contract

PHP-side contract for [Rapira](https://github.com/rapira-rs), a PHP application server written in Rust.
PHP is embedded in the server process — no FastCGI, no sockets, no serialization. This package declares the
types that boundary speaks; the extension provides the objects.

Requires PHP 8.4. Execution modes form a ladder: `Classic → SAPI Worker → Async Worker`.

## Contract

```php
namespace Rapira;

/** Throws outside worker mode. Same instance for the life of the process. */
function get_dispatcher(): Dispatcher {}

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
    public function queueSize(): int;

    /** @return int<0, max> Units handed to this worker and not yet finalized. */
    public function activeCount(): int;
}
```

Plugins narrow `receive()` natively and add their own finalization verbs:

```php
namespace Rapira\Http;

interface HttpDispatcher extends \Rapira\Dispatcher
{
    public function tryReceive(): ?Exchange;

    public function receive(int $timeout = -1): Exchange;
}

/** One request/response exchange: the request data plus the verbs that answer it. */
interface Exchange extends \Rapira\Work
{
    public function getRequest(): Request;

    /** Commits status and headers; optional, the first body write commits `200`. Committing is not
     *  sending — the bytes coalesce with the first body chunk. */
    public function writeHeader(int $status = 200, array $headers = []): void;

    /** Reaches the wire. `$eos` ends the response and finalizes the exchange. */
    public function writeBody(string $content, bool $eos = true): void;

    /** The other ending: a trailer section. Nothing the client needs — intermediaries discard trailers. */
    public function writeTrailers(array $trailers): void;

    /** Force a committed head out before any body exists, giving up `content-length`. */
    public function flush(): void;

    /** One `103 Early Hints`, repeatable until the head commits. Advisory: the host drops it where
     *  the protocol makes it unsafe. */
    public function sendEarlyHints(array $headers): void;
}

final readonly class Request
{
    public function __construct(
        public string $method,      // uppercase, as received
        public string $uri,         // absolute, synthesized: listener scheme + Host authority
        public string $target,      // request-target byte-for-byte — what SigV4 signed
        public string $protocol,    // HTTP/1.1, HTTP/2, HTTP/3
        public array $headers,      // as received, one entry per value, not normalized
        public string $body,        // whole: the host buffers before dispatching
        public string $remoteAddr,
        public int $remotePort,
        public string $serverAddr,  // which socket took the call, not the Host header
        public int $serverPort,
        public ?Tls $tls,           // null on a plaintext listener
        public float $receivedAt,   // when the host accepted it, not when the worker took it
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
  main context cannot be suspended.
- `write*` builds the response and commits, without promising the wire: a committed head coalesces with the
  first body chunk, so a one-shot response is one write with a computed `content-length`, and `flush()`
  trades that away when the head has to arrive first. `send*` emits a message of its own, immediately —
  `sendEarlyHints()` is a separate interim response with no later event to coalesce with.
- Finalization verbs live on the unit and are per-plugin. `Work` carries only the two facts a generic layer
  cannot compute for itself.
- The response is written incrementally, and the SDK builds the atomic conveniences — a PSR-7 stream,
  `respond($response)` — because incremental composes into atomic and not the other way round.
- The request is deliberately not symmetric with it: the host collects the whole body before dispatching and
  hands it over as one string, so no PHP worker is held open by a slow uploader and `Expect: 100-continue`,
  `413` and malformed framing never reach PHP. The cost is answering `401` before the upload arrives —
  bandwidth, not worker time.
- Each plugin owns a first-level namespace: `Rapira\Http`, later `Rapira\Grpc`, `Rapira\Jobs`. `Rapira\`
  holds only what they share — `Dispatcher`, `Work`, `DispatcherInfo`, `LogLevel`, the functions — and
  `Rapira\Exception\` only the exceptions more than one plugin can throw. A plugin's own, like
  `Http\HeadAlreadySentError`, sit beside its interfaces.
- Unfinalized units are the host's problem: it fails them and recycles the worker per pool policy.
- Cancellation is cooperative. VM interrupts are a pool watchdog, not routine cancellation, and cannot fire
  while PHP is inside a blocking native call.
- Interface members are methods, not hooked properties — internal classes cannot declare hooks, and the stub
  generator has no syntax for them.
- The consumer is Rapira's SDK, not application code. Test for any addition: could the SDK compute it
  itself? Then it does not belong here.
- Interfaces state behaviour, not the reasoning behind it. Why something is shaped the way it is, or absent,
  is recorded here.

## Exceptions

`Rapira\Exception\TimeoutException` and `Rapira\Exception\ClosedException` are both caught routinely — the
first by a loop doing periodic chores, the second as the loop's exit — so both need types. They extend the
SPL class that fits (`\RuntimeException`) and implement the `Rapira\Exception\RapiraException` marker, so
"anything from Rapira" is catchable without forcing every error into one hierarchy.

`AlreadyFinalizedError` extends `\Error` — nobody catches it, the script fatals, the host cleans up. Not
`\LogicException`, which frameworks catch broadly enough to swallow it. The error/exception split is left
to the native hierarchy, so `instanceof \Error` keeps meaning "your code is wrong" and no second marker is
needed for it.

`WorkDiscardedException` is finalizing a unit the host had already closed — expired deadline, drain, gone
client, lease lost to another worker. The worker broke no rule, so it is a runtime exception and not an
error, and a handler catches it to log the loss. Polling `Work::isCancelled()` at checkpoints avoids
getting there at all.

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
| `respond(Response)`, a `Response` value object | the SDK builds it on `writeHeader()`/`writeBody()`; the reverse is impossible, since an atomic response cannot send a head before the first body byte exists |
| `sendInformational(int $status, ...)` | `100` belongs to whoever collects the body, and that is the host; `101` needs the raw socket, so it is a plugin and not a status code; `102` is an idle ping on a timer, also the host's. A named `sendEarlyHints()` cannot be used to violate the protocol, and a verb can still be added later |
| derived request data (query, cookies, negotiation) | `parse_str()` and friends already do it, per framework conventions |
| backing values on `LogLevel` | the host matches cases, so no string is on the wire — and without `tryFrom()` a PSR-3 bridge cannot half-map: `tryFrom($psrLevel) ?? LogLevel::Info` would file every `emergency` under `Info`, since the four unmatched PSR-3 names include all three levels above `error` |
| request trailers | Pingora cannot see them — `// TODO: proper trailer handling and parsing` on the HTTP/1.1 trailer section, a bare `// TODO: trailer` on the HTTP/2 server session — so S3-style `x-amz-trailer` checksums are unreachable in both versions. A plain field on `Request` when that changes, which the buffered body allows: Go needs a mutable `Request.Trailer` only because it streams |
| a live request stream, read while the client is still sending | it would pin a PHP worker for the length of the upload, so a handful of slow clients could idle the pool — the reason nginx defaults to `proxy_request_buffering on`. It also removes the HTTP/1.1 read-write deadlock Go's `EnableFullDuplex` exists to opt into |
| `readBody(): ?string` handing the buffered body over in chunks | bounds what PHP holds, but serves neither case well: a 1 GB upload wants a path to `rename()`, a 20 KB JSON body wants `$request->body` — see Open 5 |
| `Content-Type` sniffing on the first body write | Go guesses from the first 512 bytes because a Go handler may not know; a PHP application does, and a guessed type the browser then trusts is what `nosniff` exists to stop. A computed `content-length` stays — arithmetic, not a guess |
| an optional-capability object (`http.ResponseController`) | Go needs one because `ResponseWriter` is a public interface with third-party implementations, so `Flush` arrives by type assertion. `Exchange` has a single implementation, shipped with the contract, so `flush()` sits on it |

## Open

1. **Scheduler ownership.** Bare fibers give no concurrency — a fiber inside `PDO::query()` blocks the
   thread. Either the application brings amphp/revolt, and then a natively blocking `receive()` freezes its
   loop and the contract needs an awaitable primitive instead; or the host hooks blocking I/O and becomes
   the scheduler, and `receive()` stands as written. This decides what an integration may do around
   `receive()`.
2. **Deadlines.** `isCancelled()` answers "still wanted?"; a handler budgeting its own work needs the
   remaining time too. Cheap to add for consumers, awkward once plugins pick spellings. Go's answer is
   `ResponseController.SetReadDeadline`/`SetWriteDeadline` — the handler sets a deadline rather than reading
   what is left of one.
3. **Non-dispatcher plugins.** A logger or KV client is not a stream of work units and needs a second
   acquisition path, without bringing back config objects.
4. **Superglobal hydration** as an explicit call on the unit, so the PSR path does not pay for globals it
   never reads.
5. **Large uploads.** `Request::$body` is a string, so the largest request Rapira accepts is bounded by what
   a worker can hold, and the host's body limit belongs below `memory_limit`. That is the right trade for
   request traffic and the wrong one for an upload endpoint, where the handler wants to keep the bytes and a
   path the host spooled to would be a `rename()`. So `Request::$bodyPath`, or a `tmpPath` on a per-part
   `UploadedFile`, is the likely addition — which of the two is the same question as multipart, since real
   upload traffic is `multipart/form-data` and wants parts, not one body. Either is an added field that
   breaks no signature, so it can wait for a consumer that needs it.
6. **`writeTrailers()` is provisional**, pending a team call. The split is browser HTTP versus
   machine-to-machine HTTP, not dead versus alive. For it: RFC 9530 (Digest Fields, Standards Track, 2024)
   gives §6.4 and appendix B.11 to a worked `Trailer: Repr-Digest` response, motivated verbatim by computing
   the digest "while streaming content and thus mitigate resource consumption" — a standard spelling for the
   one case this method serves; S3-compatible streaming uploads put `x-amz-checksum-*` in request trailers;
   Go, Node and Servlet 4.0 implement them, and Envoy, nginx and HAProxy pass them, as they must to proxy
   gRPC at all. Against: browsers never expose trailers to JavaScript, RFC 9110 §6.5.1 says intermediaries
   discard them "in most cases" and therefore that a server SHOULD NOT send one the user agent needs,
   neither PSR-7 nor HttpFoundation has a vocabulary for them, and Go's own `Request.Trailer` doc concludes
   "Few HTTP clients, servers, or proxies support HTTP trailers". Decisively: the HTTP front is Pingora,
   whose `write_response_trailers` is `Self::H1(_) => Ok(())` — `// TODO: support trailers for h1`, still
   open on main — so on HTTP/1.1 our own host drops them silently, and the method does anything at all only
   over end-to-end HTTP/2. Dropping it costs one method and no signature.

## References

- [rapira-rs/rapira#38](https://github.com/rapira-rs/rapira/issues/38) — plugin handler API; its
  config-object direction was superseded within its own thread.
- [rapira-rs/rapira#45](https://github.com/rapira-rs/rapira/issues/45) — dispatcher interfaces; origin of
  the pull model and the finalization discipline.
- [`docs/dispatcher-blocking-vs-timeout.md`](docs/dispatcher-blocking-vs-timeout.md) — why bounded waiting
  survived and why `null` did not carry it.
