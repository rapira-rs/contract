# rapira/contract

PHP-side contract for [Rapira](https://github.com/rapira-rs), a PHP application server written in Rust.
PHP is embedded in the server process — no FastCGI, no sockets, no serialization. This package declares the
types that boundary speaks; the extension provides the objects.

Requires PHP 8.4 — the extension's floor; the stubs themselves use nothing newer than 8.2. Execution
modes form a ladder — `Rapira\Mode`, read back at runtime from `get_mode()`: `Classic` boots the
script per request, `Worker` boots it once and serves requests one after another through the SAPI
superglobals, `Dispatcher` takes requests as units of work through this contract — one at a time or
concurrently on fibers. Only `Dispatcher` mode has a dispatcher; everything below lives on that rung.

## Contract

```php
namespace Rapira;

/** Throws Exception\NoDispatcherError outside Dispatcher mode. Same instance for the life of the process. */
function get_dispatcher(): Dispatcher {}

/** The mode the host launched this process in. Fixed for the life of the process. */
function get_mode(): Mode {}

/** Version of the running Rapira server. */
function get_version(): string {}

/** Queued to the host under the `app` target. Never blocks, never throws. */
function log(string $message, LogLevel $level = LogLevel::Info, array $context = []): void {}

/** No backing values; a PSR-3 bridge squashes eight levels into these five. */
enum LogLevel { case Error; case Warning; case Info; case Debug; case Trace; }

enum Mode { case Classic; case Worker; case Dispatcher; }

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

Plugins narrow `receive()` natively and add their own finalization verbs. Each plugin's surface, and
the reasoning behind its shape, lives beside its stubs:

- [`Rapira\Http`](src/Http/README.md) — the request/response exchange, framing, content coding.
- [`Rapira\Grpc`](src/Grpc/README.md) — the call and its responder, streams, statuses, metadata.

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
- Finalization verbs live on the unit and are per-plugin. `Work` carries only the two facts a generic layer
  cannot compute for itself.
- Addresses are the union `InetAddress|UnixAddress`, mirroring Pingora's own `SocketAddr`: a unix
  listener has no IP and its connecting peer usually no name at all, so "a port exists" is a fact the
  type states — not a zero sentinel carrying two meanings. They live in `Rapira\`: HTTP and gRPC both
  put them on their request shapes, and what plugins share, the root holds.
- Each plugin owns a first-level namespace: `Rapira\Http`, `Rapira\Grpc`, later `Rapira\Jobs`. `Rapira\`
  holds only what they share — `Dispatcher`, `Work`, `DispatcherInfo`, `LogLevel`, the address types,
  `Tls`, the functions — and `Rapira\Exception\` only the exceptions more than one plugin can throw. A plugin's
  own live the same way, in its own `Exception\` sub-namespace — `Http\Exception\HeadAlreadyWrittenError` —
  one rule for where a throwable lives, whichever surface throws it. A refinement of a type is a
  sibling, not a child: `Grpc\StreamingRequest` extends `Grpc\Call` in the same namespace — a subtype
  is a way the parent comes, not something it hands out. A directory under a type's name holds only
  the shapes that type alone hands out — `Grpc\Call\Context` exists through `Call::getContext()` and
  nothing else — while what worker code constructs itself, `Status` and the boot-time descriptors,
  stays at the plugin root: the directory answers who gives you the object.
- Unfinalized units are the host's problem: it fails them and recycles the worker per pool policy.
- Cancellation is cooperative. VM interrupts are a pool watchdog, not routine cancellation, and cannot fire
  while PHP is inside a blocking native call.
- Interface members are methods, not hooked properties — internal classes cannot declare hooks, and the stub
  generator has no syntax for them.
- Behaviour is an interface, data is a final readonly class with a public constructor. So every type is
  either mockable or constructible, and a test never needs the host: a fake `StreamingRequest` returns an
  array-backed `MessageStream`, a fixture `Context` carries a `new Metadata([...])`.
- The consumer is Rapira's SDK, not application code. Test for any addition: could the SDK compute it
  itself? Then it does not belong here — unless the fact lives in the type system: an SDK can wrap
  `next()` into an iterable value, but only `MessageStream` itself can pass an `iterable` parameter.
- Interfaces state behaviour, not the reasoning behind it. Why something is shaped the way it is, or absent,
  is recorded here.

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
needed for it. `NoDispatcherError` is the same shape: a worker script running where no dispatcher
exists is wrong by construction.

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
| `receiveMany()` | latency poison for request traffic; batching is plugin vocabulary |
| backing values on `LogLevel` | the host matches cases, so no string is on the wire — and without `tryFrom()` a PSR-3 bridge cannot half-map: `tryFrom($level) ?? Info` would file every `emergency` under `Info` |
| CN, SAN and issuer on `Tls`, or a field per certificate attribute | fingerprint pinning covers mTLS identity, and Pingora's `SslDigest` exposes nothing more ([pingora#421](https://github.com/cloudflare/pingora/issues/421)). When names are needed, the addition is one `certPem` field with the whole certificate — `openssl_x509_parse()` reads every attribute in userland |

## Open

1. **Non-dispatcher plugins.** A logger richer than `log()` — its own target, its own sink — or a KV
   client is not a stream of work units and needs a second acquisition path, without bringing back
   config objects.

## References

- [rapira-rs/rapira#38](https://github.com/rapira-rs/rapira/issues/38) — plugin handler API; its
  config-object direction was superseded within its own thread.
- [rapira-rs/rapira#45](https://github.com/rapira-rs/rapira/issues/45) — dispatcher interfaces; origin of
  the pull model and the finalization discipline.
