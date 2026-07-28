# rapira/contract

PHP-side contract for [Rapira](https://github.com/rapira-rs), a PHP application server written in Rust.
PHP is embedded in the server process — no FastCGI, no sockets, no serialization. This package declares the
types that boundary speaks; the extension provides the objects.

Requires PHP 8.4.

## Scope

Execution modes form a ladder: `Classic → SAPI Worker → Async Worker`.

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
namespace Rapira\Plugin\Http;

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
    public function writeHead(int $status = 200, array $headers = []): void;

    /** Reaches the wire. `$eos` ends the response and finalizes the exchange. */
    public function writeBody(string $content, bool $eos = true): void;

    /** The other ending: a trailer section. Advisory — the host drops it where RFC 9112 forbids one. */
    public function writeTrailers(array $trailers): void;

    /** Force a committed head out before any body exists, giving up `content-length`. */
    public function flush(): void;

    /** One `103 Early Hints`, repeatable until the head commits. Advisory: the host drops it where
     *  the protocol makes it unsafe. */
    public function sendEarlyHints(array $headers): void;
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
  `sendEarlyHints()` is a separate interim response with no later event to coalesce with. The verb tells
  you which of the two you are doing.
- Finalization verbs live on the unit and are per-plugin. `Work` carries only the two facts a generic layer
  cannot compute for itself.
- Unfinalized units are the host's problem: it fails them and recycles the worker per pool policy.
- Cancellation is cooperative. VM interrupts are a pool watchdog, not routine cancellation, and cannot fire
  while PHP is inside a blocking native call.
- Interface members are methods, not hooked properties — internal classes cannot declare hooks, and the stub
  generator has no syntax for them.
- The consumer is Rapira's SDK, not application code. Test for any addition: could the SDK compute it
  itself? Then it does not belong here.

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
| `respond(Response)`, a `Response` value object | the SDK can build it on `writeHead()`/`writeBody()`; the reverse is impossible, because an atomic response cannot send a head before the first body byte exists |
| `sendInformational(int $status, ...)` | `100` is answered by the head written before the body is read, never by a verb; `101` needs the raw socket, so it is a plugin and not a status code; `102` is an idle ping on a timer, which is the host's; a named `sendEarlyHints()` cannot be used to violate the protocol, and adding a verb later costs nothing |
| derived request data (query, cookies, negotiation) | `parse_str()` and friends already do it, per framework conventions |
| request trailers | S3-style `x-amz-trailer` checksums arrive after a buffered body the host has already closed; a field on `Request`, not a paired verb, and it waits on the body-streaming decision |

## Open

1. **Scheduler ownership.** Bare fibers give no concurrency — a fiber inside `PDO::query()` blocks the
   thread. Either the application brings amphp/revolt, and then a natively blocking `receive()` freezes its
   loop and the contract needs an awaitable primitive instead; or the host hooks blocking I/O and becomes
   the scheduler, and `receive()` stands as written. This decides what an integration may do around
   `receive()`.
2. **Deadlines.** `isCancelled()` answers "still wanted?"; a handler budgeting its own work needs the
   remaining time too. Cheap to add for consumers, awkward once plugins pick spellings.
3. **Non-dispatcher plugins.** A logger or KV client is not a stream of work units and needs a second
   acquisition path, without bringing back config objects.
4. **Superglobal hydration** as an explicit call on the unit, so the PSR path does not pay for globals it
   never reads.
5. **Request bodies are buffered.** `Request::$body` is a string, which commits the host to buffering the
   whole body under a configured limit. Streaming uploads want a pull primitive instead, and the two cannot
   both consume the same bytes — so adding one later is a mode, not a pure addition. Multipart spooled
   host-side (`UploadedFile` with a `tmpPath`) is the same decision from the other end.
6. **`writeTrailers()` is provisional**, pending a team call. Nothing on the framework path can reach it:
   neither PSR-7 nor HttpFoundation has a vocabulary for trailers, `fetch` and XHR do not expose them, and
   proxies strip them. The load-bearing user is gRPC's `grpc-status`, which is that plugin's own
   vocabulary — leaving `Content-Digest` over a long stream as the case that argues for keeping it here.
   Dropping it costs one method and no signature.

## References

- [rapira-rs/rapira#38](https://github.com/rapira-rs/rapira/issues/38) — plugin handler API; its
  config-object direction was superseded within its own thread.
- [rapira-rs/rapira#45](https://github.com/rapira-rs/rapira/issues/45) — dispatcher interfaces; origin of
  the pull model and the finalization discipline.
- [`docs/dispatcher-blocking-vs-timeout.md`](docs/dispatcher-blocking-vs-timeout.md) — why bounded waiting
  survived and why `null` did not carry it.
