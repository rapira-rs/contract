# Rapira contract — WebSocket

The shared core — `Dispatcher`, `Work`, the address types, the rules every plugin obeys — lives in
the [package README](../../README.md); this file holds only what is WebSocket's own.

The host speaks the protocol — RFC 6455, and whatever extends it — and holds every connection, outside
the PHP workers. PHP gets short units and a handle to send with; it never holds a socket and never sees a
frame.

```php
namespace Rapira\WebSocket;

/** From any pool. NoWebSocketError on a host with no `websocket` section. */
function get_connection(string $id): Connection {}

interface WebSocketDispatcher extends \Rapira\Dispatcher
{
    public function tryReceive(): Handshake|Message|Close|null;

    public function receive(int $timeout = -1): Handshake|Message|Close;

    public function getInfo(): WebSocketDispatcherInfo;
}

/** A client asking to open a connection. Nothing is a connection until accepted. */
interface Handshake extends \Rapira\Work
{
    public function getRequest(): \Rapira\Http\Request;        // http/https $uri, empty body, GET or CONNECT
    public function getRequestedSubprotocols(): array;         // list<non-empty-string>, client's order

    /** 101 on HTTP/1.1, 200 on HTTP/2 and HTTP/3. $attachment rides every later unit of the connection. */
    public function accept(?string $subprotocol = null, array $headers = [], string $attachment = ''): Connection;

    public function reject(int $status = 403, array $headers = [], string $body = ''): void;  // 300–599
}

/** One whole data message. The connection's next unit waits for complete(). */
interface Message extends \Rapira\Work
{
    public function getConnection(): Connection;
    public function getAttachment(): string;
    public function getType(): MessageType;
    public function getPayload(): string;
    public function complete(?string $attachment = null): void;  // a string replaces the attachment
}

/** The connection ended. At most one per accepted connection, always its last unit. */
interface Close extends \Rapira\Work
{
    public function getConnection(): Connection;
    public function getAttachment(): string;
    public function getCode(): int;                            // first Close frame received; 1005 none in it, 1006 none
    public function getReason(): string;
    public function wasClean(): bool;
    public function complete(): void;
}

/** Send side only. Never waits; on an ended connection sends are discarded. */
interface Connection
{
    public function getId(): string;                           // never reused
    public function send(string $payload, MessageType $type = MessageType::Text): void;
    public function close(int $code = 1000, string $reason = ''): void;
}

enum MessageType { case Text; case Binary; }
```

A chat, whole — the same worker script as the HTTP pool, split on `name()`:

```php
use Rapira\Exception\ClosedException;
use Rapira\WebSocket\Close;
use Rapira\WebSocket\Handshake;
use Rapira\WebSocket\WebSocketDispatcher;
use function Rapira\WebSocket\get_connection;

$dispatcher = \Rapira\get_dispatcher();
if ($dispatcher->name() !== 'websocket') {
    serveHttp($dispatcher);
    return;
}
assert($dispatcher instanceof WebSocketDispatcher);

try {
    while (true) {
        $unit = $dispatcher->receive();

        if ($unit instanceof Handshake) {
            $headers = array_change_key_case($unit->getRequest()->headers);
            $user = $auth->fromCookies($headers['cookie'] ?? []);
            if (!$origins->allows($headers['origin'][0] ?? null) || $user === null) {
                $unit->reject(403);
                continue;
            }
            $unit->accept(attachment: json_encode(['user' => $user->id, 'room' => null], JSON_THROW_ON_ERROR));
            continue;
        }

        $state = json_decode($unit->getAttachment(), true, flags: JSON_THROW_ON_ERROR);

        if ($unit instanceof Close) {
            $rooms->leave($state['room'], $unit->getConnection()->getId());
            $unit->complete();
            continue;
        }

        $command = json_decode($unit->getPayload(), true, flags: JSON_THROW_ON_ERROR);
        if ($command['type'] === 'join') {
            $rooms->join($command['room'], $unit->getConnection()->getId());
            $unit->complete(json_encode([...$state, 'room' => $command['room']], JSON_THROW_ON_ERROR));
            continue;
        }
        foreach ($rooms->members($state['room']) as $id) {
            get_connection($id)->send($unit->getPayload());
        }
        $unit->complete();
    }
} catch (ClosedException) {
    // drained: every Close was handed out first
}
```

And a push from an HTTP handler, a job, anywhere: `get_connection($id)->send($json)`.

## Rules

- **Units, not connections.** A connection lasts hours, a worker holds one unit at a time, and a unit held
  for a connection's life would pin a worker per client — the reason the HTTP plugin refuses a live
  request stream. So the host holds the connection and PHP takes three short units: a handshake to answer,
  a message to handle, an ending to clean up after. Every unit is also a reset point, which is what
  long-running frameworks already reset their services at.
- **Sequential per connection, free across them.** A connection's units come in order, and the next is
  handed out only after the previous one is finalized — to any worker of the pool. `complete()` is that
  release, and not calling it is the backpressure: the host stops reading the client meanwhile. When the
  host holds several units per worker, different connections run concurrently, each still in order.
- **One `Close`, always last.** Whoever ends a connection — the client, a worker's `close()`, the host over
  a limit or at shutdown — its `Close` unit follows, so presence and membership have one place to be
  undone. `error` and `close`, two WHATWG events, are one unit here: `wasClean()` false. A handshake that
  was never accepted, or whose client left before `accept()`, has none. At most once, and not at all when
  the host process itself dies — state kept outside the host for a connection wants an expiry too.
- **The attachment is the connection's state.** Any worker may take the next unit, so nothing a worker keeps
  in memory reaches it. The host keeps an opaque string with the connection instead: set by `accept()`,
  replaced by `Message::complete($attachment)` — race-free, since no other unit of that connection runs
  meanwhile — and returned on every unit. A string, not an array: it crosses processes, the host never
  reads it, and its limit is a byte count.
- **A connection is a send handle.** `Connection` carries no data and answers no liveness question — an
  answer would be stale by the time the frame reached the wire. `send()` never waits and never throws for a
  gone connection: it discards, as the WHATWG `send()` does on a closing socket. The end of a connection
  is learned in one place, its `Close` unit. Ids are never reused, across restarts included, so a stored id
  can go dead but never reaches someone else.
- **One `send()`, the type beside the payload.** A PHP string is bytes either way, so text or binary is an
  argument; relaying a message as received is `send($m->getPayload(), $m->getType())`. Text must be valid
  UTF-8 (RFC 6455 §5.6).
- **Close codes are an open set**, so `int`. A server may send `1000`–`1003`, `1007`–`1014` and
  `3000`–`4999` — the protocol codes a browser may not (`1001`, `1008`, `1011`) included. `1005`, `1006`
  and `1015` never travel, `1004` is reserved, and the rest of `1000`–`2999` waits for registration, which
  is a host update and not a signature change. Reasons are UTF-8 and at most 123 bytes.
  `Close::getCode()` is RFC 6455 §7.1.5's — the first Close frame received from the client, whoever
  started — the same value the WHATWG `CloseEvent` carries, with `1005` for a frame without a status and
  `1006` for no frame at all, which is why it is never null.
- **`accept()` is a verb, not `writeHead(101)`.** HTTP/1.1 accepts with `101`, HTTP/2 and HTTP/3 with
  `200` (RFC 8441, RFC 9220); the worker states the decision and the host spells it. The same fact makes
  `reject()` refuse `2xx` — over HTTP/2 a `2xx` means accepted. The handshake's own fields are the host's:
  `sec-websocket-*` in `accept()`'s headers is a `\ValueError`, and the subprotocol has its parameter.
- **The handshake is an HTTP request, so it reuses `Http\Request`.** Plugins otherwise keep their own
  shapes — gRPC's `Call\Context` does — but a handshake is one, byte for byte, and one class means one
  hydration path into PSR-7 or HttpFoundation. `$uri` keeps `http`/`https`, never `ws`/`wss`, so
  `isSecure()` holds. One thing the SDK handles: `$method` is `CONNECT` over HTTP/2 and HTTP/3, and a
  router that matches the endpoint as `GET` is shown `GET`; `$target` is the path either way.
- **`getRequestedSubprotocols()` lifts a header.** It is derivable from `sec-websocket-protocol`, but
  `accept()` checks its choice against that list, and a client given a subprotocol it did not offer fails
  the connection (RFC 6455 §4.1). The host parses it once, so no two parsers can disagree about it.
- **`Origin` is the application's check.** Browsers apply no same-origin policy to WebSockets and send the
  site's cookies with a handshake started from any page, so with cookie auth an unchecked `Origin` is
  cross-site WebSocket hijacking (RFC 6455 §10.2). The host does not check it; neither do framework
  firewalls on their own.
- **Lost units.** A handshake dropped unanswered gets `500`, as an HTTP exchange does. A message dropped
  unfinalized is a failed handler: the host closes the connection with `1011`. A lost `Close` is only
  logged.
- **The host never finalizes a `Message` or a `Close`**, so their `complete()` never meets
  `WorkDiscardedException`. `isCancelled()` is true on a handshake whose client left and on a message
  whose connection is closing — sends to that connection are discarded, completing is still expected —
  and never on a `Close`. A `\ValueError` from `accept()` or `complete()` finalizes nothing.

## Host

What the contract assumes the host does, and what never reaches PHP:

- Holds connections outside the PHP workers: recycling a worker, scale-down, a deploy that replaces
  workers — none drops a connection. A worker's fatal drops only the connection whose `Message` it held
  (`1011`, the lost-unit rule). On shutdown the host stops taking handshakes, closes every connection with
  `1001` (`1012` when restarting) and hands out their `Close` units before `ClosedException`.
- Routes: any unit to any worker of the `websocket` pool, and `get_connection()` from any pool to wherever
  the connection lives.
- Answers a broken handshake itself: a version other than `13` gets `426` with `Sec-WebSocket-Version: 13`,
  a missing or malformed key `400`, a request that asks for no upgrade on a WebSocket-only listener `426`.
- Pings, pongs and idle timeouts — time a connection spends paused on an unfinalized `Message` does not
  count against them; fragment reassembly; masking; UTF-8 validation of inbound text (`1007`); inbound
  Close frames with a forbidden code or reason (the connection fails, `getCode()` reports `1006`);
  permessage-deflate (RFC 7692).
- Enforces limits from `rapira.toml`: message size (`1009`), attachment size, the outbound buffer per
  connection (`1008` for a client that does not read).

## Framework integration

Checked against Symfony 7.4, with nothing Symfony-specific in the contract:

- One worker script for both pools; the runtime's runner chooses its loop by `get_dispatcher()->name()`.
- A handshake hydrates an HttpFoundation `Request` the way an HTTP exchange does and goes through the
  kernel — routing, firewall, the controller deciding — and the SDK maps the answer onto `accept()` or
  `reject()`.
- Every unit is short, so `services_resetter` runs between units exactly as between HTTP requests.
- Later units carry no request and no cookies: the SDK rebuilds identity from the attachment.
- Controllers and Messenger handlers in the HTTP pool push with `get_connection()`.
- No `psr/*` or Symfony dependency; PHP 8.4 sits above Symfony 7.4's floor.

## Evolution

What stays stable is the caller's side: code that calls these methods keeps working. Hand-written
implementations of the interfaces are not covered — the host is the one implementer, and test doubles come
from mocking libraries.

- **Wire revisions** — permessage-deflate, WebSockets over HTTP/2 (RFC 8441) and HTTP/3 (RFC 9220), a new
  protocol version — are host work and `rapira.toml`; nothing here changes. The SDK's one day-one
  obligation, `CONNECT` shown as `GET`, is what keeps HTTP/2 from being a surprise.
- **A new decision at the handshake** — per-connection compression, a message size — is a trailing
  optional parameter on `accept()`.
- **A new fact on a unit** is a new method on its interface. `int $code` can widen to `int|CloseCode`.
- **A new unit kind** — inbound pongs, a message streamed in parts, as the WHATWG `WebSocketStream` does
  client-side — or a new `MessageType` (RFC 6455 reserves opcodes `0x3`–`0x7`) is the one breaking axis.
  It is delivered only where `rapira.toml` opts in, so no deployment meets a kind its SDK was not written
  for; the widened union still shows in static analysis, and that release says so.

## Not in the contract

| Omitted | Why |
|---|---|
| a connection as one long-held unit, with `receive()` on it (Ratchet, Swoole) | pins a worker per client for hours, and leaves no reset point between messages |
| WebSocket units on `HttpDispatcher` | widens `receive(): Exchange` into a union and breaks every HTTP adapter |
| `Exchange::upgrade()`, the HTTP pool opening connections for the WebSocket pool | ties the HTTP plugin to this one and grows `Exchange`; the pool that owns connections answers their handshakes |
| `getConnection()` on `WebSocketDispatcher` | the dispatcher exists only in the `websocket` pool, and a push mostly starts in the HTTP pool |
| `Handshake extends Http\Exchange` | refusing through the HTTP verbs would need `writeHead(101)` and `2xx` to throw — a subtype narrowing what its parent accepts |
| `readyState`, `isOpen()`, `bufferedAmount` | stale by the next line; the verb discards, `Close` reports |
| `send(): bool`, an exception for a gone connection | the answer is stale by the time it returns, and needs a round trip for a connection held elsewhere |
| `sendText()` and `sendBinary()` | relaying as received would need a `match` |
| a `CloseCode` enum | close codes are an open set — `3000`–`4999` and future registrations |
| an array attachment | it crosses processes; one opaque string has one limit and no mapping rules |
| the negotiated subprotocol, the handshake request on later units | the SDK knows both at `accept()` and puts what it needs in the attachment |
| sending pings, receiving pongs | keepalive is the host's; the WHATWG API exposes neither |
| channels, rooms, broadcast | fan-out over `get_connection()` is userland; host-side pub/sub is a later, additive feature |
| a parent interface for `Message` and `Close` | their `complete()` differ; a parent is extractable later at zero cost |
