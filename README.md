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
- Writing commits without promising the wire: a committed head coalesces with the first body chunk, so a
  one-shot response is one write with a computed `content-length`, and `flush()` trades that away when the
  head has to arrive first. An interim `1xx` head is the exception the protocol itself makes — a complete
  message of its own, with no later event to coalesce with, so it goes out at once. `send*` says the bytes
  come from somewhere other than the caller: `sendFile()` names a path and the host reads it.
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

## Framing

The host frames the response and the worker states what it knows. `transfer-encoding` and the other
hop-by-hop fields are dropped from a head: chunked is never asked for, it is chosen.

- **A `content-length` in the head is honoured**, and then enforced. Pingora's writer is what makes that
  cheap: in content-length mode it truncates the write to what remains and reports how many bytes it took,
  so a surplus is both detectable and unsendable — which matters, because surplus bytes on a reused
  connection are read as the start of the next response. The write that would exceed the declaration raises
  `Http\ContentLengthExceededError`. Ending the response short of it instead leaves a promise unkept, so the
  host closes the connection rather than reusing it.
- **No `content-length`, and the response ends on its first body write** — one `writeBody($all)` or one
  `sendFile($path)` — so the host computes the length. The head is still buffered at that point, which is
  why size is no object here; Go computes one only under 2 KB (`bufferBeforeChunkingSize`), because it has
  to decide while streaming into a buffer.
- **No `content-length` and more than one write, or a head already forced out by `flush()`** — HTTP/1.1
  chunked, HTTP/2 and later plain DATA frames ending on `END_STREAM`. Never close-delimited: that is
  Pingora's fallback when a head carries neither field, and its own TODO there notes that keep-alive dies
  with it.
- **`204`, `304`, and any response to `HEAD`** carry neither body nor trailer section, "regardless of the
  header fields present in the message" (RFC 9112 §6.3). Body and trailer writes are accepted and dropped,
  which is what lets a handler answer `HEAD` with the same code path as `GET`. A `content-length` is not
  synthesised from what was dropped: RFC 9110 §8.6 forbids one on `204` outright, and permits one on `HEAD`
  or `304` only if it equals what a `GET` or a `200` would have sent — which, the body having been in hand,
  it can.
- **`1xx` heads carry no framing fields at all.**

### Content coding

`Content-Encoding` belongs to the representation, not to the transfer: "the representation is defined in
terms of the coded form, and all other metadata about the representation is about the coded form"
(RFC 9110 §8.4). So `content-length`, `etag`, `Repr-Digest` and byte ranges all describe the *coded* bytes.
A host-side compression middleware follows from that sentence:

- It leaves alone any response that already carries `content-encoding` — the worker coded it, and those
  bytes are the representation. This is also the safe way to serve a large asset: `sendFile('asset.br')`
  with `content-encoding: br` is a byte-stable stream, so a strong `etag` and byte ranges both stay honest.
- It never codes a `206`, or a `sendFile()` slice. `content-range` counts offsets in the representation the
  handler sliced, and coding afterwards leaves the field and the body describing different things.
- When it does code, it computes `content-length` itself, adds `Vary: Accept-Encoding`, drops
  `Accept-Ranges`, and weakens or removes the worker's `etag`. Coding on the fly is not byte-stable — level,
  library version and flush points all move the bytes — so a strong validator would be a lie, and `If-Range`
  accepts nothing weaker (RFC 9110 §13.1.5). That is the whole reason range requests and on-the-fly
  compression do not mix, and nginx draws the line in the same place: `gzip` gives up ranges, `gzip_static`
  keeps them.
- It honours `cache-control: no-transform` (RFC 9111 §5.2.2.6) as the per-response opt-out. Not a nicety: a
  response that mixes attacker-controlled input with a secret leaks the secret through its compressed length
  (BREACH).
- On a streamed response it either sync-flushes the compressor at every chunk that does not end the
  response, or leaves that response alone. A compressor holding bytes back turns `flush()` into a lie, and
  is how `text/event-stream` ends up silent.

## Exceptions

`Rapira\Exception\TimeoutException` and `Rapira\Exception\ClosedException` are both caught routinely — the
first by a loop doing periodic chores, the second as the loop's exit — so both need types. They extend the
SPL class that fits (`\RuntimeException`) and implement the `Rapira\Exception\RapiraException` marker, so
"anything from Rapira" is catchable without forcing every error into one hierarchy.

`AlreadyFinalizedError` extends `\Error` — nobody catches it, the script fatals, the host cleans up. Not
`\LogicException`, which frameworks catch broadly enough to swallow it. The error/exception split is left
to the native hierarchy, so `instanceof \Error` keeps meaning "your code is wrong" and no second marker is
needed for it.

`Http\ContentLengthExceededError` and `Http\HeadAlreadySentError` are both `\Error` for the same reason as
`AlreadyFinalizedError`: the response is already unsalvageable by the time either is raised, so there is
nothing for a handler to do but fatal. `Http\FileNotSendableException` is the opposite case and therefore an
exception — it is raised before `sendFile()` has written anything, so `404` is still on the table.

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
| `respond(Response)`, a `Response` value object | the SDK builds it on `writeHead()`/`writeBody()`; the reverse is impossible, since an atomic response cannot send a head before the first body byte exists |
| a mutable header bag on the exchange — `setHeader()`, `removeHeader()`, `getHeaders()` | the head has no incremental dimension: it commits at once, so a bag is not a smaller primitive but the same commit with its intermediate state moved across the boundary. That costs two owners for one fact, one native call per header instead of one per response, and rebuilds the `header()`/`headers_list()` globals every framework wraps to escape. Go needs `Header()` because its stdlib has no response object — PHP has PSR-7, so the bag already exists in userland and arrives as one array |
| `writeStatus(int)` apart from the headers | the status line and the field lines are one section on the wire, and the fields are never known without the status, so splitting buys a second commit state and no case. A status-only response is `writeHead(204)` |
| `string` in place of `list<string>` for a header value | `Request::$headers` arrives as lists and PSR-7's `getHeaders()` returns lists, so the scalar form serves neither end of the SDK — two spellings for one fact to save two brackets |
| a verb of its own for interim responses — `sendEarlyHints()`, `sendInformational()` | `writeHead()` takes any `1xx` and puts it on the wire at once, which is what Go's `WriteHeader` and Pingora's `write_response_header` both do: one verb per head, and one host call per PHP call. The reason to split them was Go's shared mutable header map, cleared on a final status but not on an interim one — passing the fields as an argument means there is no map and no rule. Which `1xx` codes are worth sending is not policed: `101` counts as a final head, since it ends the HTTP conversation, and `100` is the host's answer to `Expect` long before a worker sees the exchange, but writing either is the worker's business |
| derived request data (query, cookies, negotiation) | `parse_str()` and friends already do it, per framework conventions |
| backing values on `LogLevel` | the host matches cases, so no string is on the wire — and without `tryFrom()` a PSR-3 bridge cannot half-map: `tryFrom($psrLevel) ?? LogLevel::Info` would file every `emergency` under `Info`, since the four unmatched PSR-3 names include all three levels above `error` |
| request trailers | Pingora cannot see them — `// TODO: proper trailer handling and parsing` on the HTTP/1.1 trailer section, a bare `// TODO: trailer` on the HTTP/2 server session — so S3-style `x-amz-trailer` checksums are unreachable in both versions. A plain field on `Request` when that changes, which the buffered body allows: Go needs a mutable `Request.Trailer` only because it streams |
| a live request stream, read while the client is still sending | it would pin a PHP worker for the length of the upload, so a handful of slow clients could idle the pool — the reason nginx defaults to `proxy_request_buffering on`. It also removes the HTTP/1.1 read-write deadlock Go's `EnableFullDuplex` exists to opt into |
| `readBody(): ?string` handing the buffered body over in chunks | bounds what PHP holds, but serves neither case well: a 1 GB upload wants a path to `rename()`, a 20 KB JSON body wants `$request->body` — see Open 5 |
| conditional requests, `Range` parsing, `etag` and `content-type` on `sendFile()` | Go's `ServeFile` does all of it, and every PHP framework already does too — Symfony's `BinaryFileResponse` parses `Range` and `If-Range` itself. The verb exists for the one thing userland cannot do, which is not holding the bytes; a `206` is the handler writing its own `content-range` and passing a slice |
| a stream resource in place of a path on `sendFile()` | a path is the one thing the host can open by itself. A PHP resource may be `php://temp`, a socket or a userland stream wrapper, and reading it would mean going back through PHP for every chunk — exactly what the verb exists to avoid |
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
   breaks no signature, so it can wait for a consumer that needs it. The response side of the same problem
   is already answered by `sendFile()`, and the pair would be symmetric: the host spools an upload to a path
   and PHP moves it, PHP names a path and the host sends it.
6. **Which filesystem root `sendFile()` may read from.** The host opens the path, so `open_basedir` does not
   apply and a handler passing user input through names any file the server process can read. A root belongs
   in `rapira.toml` by the same rule as the rest of the config, and it wants to exist before the first
   traversal rather than after one. Zero-copy is a separate and later question: Pingora has no `sendfile(2)`
   path today — the word appears in the repository only in test nginx configs — and terminating TLS in
   process rules the syscall out regardless. So what the verb buys now is that PHP never holds the bytes and
   no worker is held by a download; what it buys later is that the host can change its mind without the
   contract changing.
7. **`writeTrailers()` is provisional**, pending a team call. The split is browser HTTP versus
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
