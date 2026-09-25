# Rapira contract — WebSocket

Serve WebSocket clients from PHP workers without a worker ever holding a socket. The host speaks the
protocol (RFC 6455 and its extensions) and keeps every connection. PHP gets short units of work and a
handle to send with. Shared rules live in the [package README](../../README.md).

```php
use Rapira\WebSocket\Handshake;
use Rapira\WebSocket\Message;

$dispatcher = \Rapira\get_dispatcher();

try {
    while (true) {
        $unit = $dispatcher->receive();
        if ($unit instanceof Handshake) {
            $unit->accept();
            continue;
        }
        if ($unit instanceof Message) {
            $unit->getConnection()->send($unit->getPayload(), $unit->getType());
        }
        $unit->complete();
    }
} catch (\Rapira\Exception\ClosedException) {
}
```

## Contract

```php
namespace Rapira\WebSocket;

/** A send handle from any pool. Any string works; an unknown id discards. */
function get_connection(string $id): Connection {}

interface WebSocketDispatcher extends \Rapira\Dispatcher
{
    public function tryReceive(): Handshake|Message|Close|null;
    public function receive(int $timeout = -1): Handshake|Message|Close;
    public function getInfo(): WebSocketDispatcherInfo;   // adds connectionCount()
}

interface Handshake extends \Rapira\Work
{
    public function getRequest(): \Rapira\Http\Request;
    public function getRequestedSubprotocols(): array;    // list<non-empty-string>, client's order
    public function getConnection(): Connection;          // exists before the answer
    public function accept(?string $subprotocol = null, array $headers = [], string $attachment = ''): void;
    public function reject(int $status = 403, array $headers = [], string $body = ''): void;  // 300–599
}

interface Message extends \Rapira\Work
{
    public function getConnection(): Connection;
    public function getAttachment(): string;
    public function getType(): MessageType;
    public function getPayload(): string;
    public function getReceivedAt(): float;
    public function complete(?string $attachment = null): void;
}

interface Close extends \Rapira\Work
{
    public function getConnection(): Connection;
    public function getAttachment(): string;
    public function getCode(): int;
    public function getReason(): string;
    public function wasClean(): bool;
    public function getSentCode(): ?int;
    public function complete(): void;
}

interface Connection
{
    public function getId(): string;
    public function send(string $payload, MessageType $type = MessageType::Text): void;
    public function close(int $code = 1000, string $reason = ''): void;
}

enum MessageType { case Text; case Binary; }
```

## Units

**Finalize every unit fast; the connection's next unit waits for it.**

- A connection produces a `Handshake`, then its `Message`s, then one `Close`.
- A unit held for a connection's lifetime would pin a worker per client, so none is.
- Any worker of the pool may take any unit. Each unit is a reset point for framework services.
- One connection's units come in order: the next is handed out only after the previous one is finalized.
- Different connections are independent, and run concurrently once the host holds several units per worker.
- While a `Message` is unfinalized, the host stops reading that client. That is the backpressure. The
  pause does not count against ping or idle timeouts.
- Workers share no memory. Keep per-connection state in the attachment, shared state (rooms, presence) in
  an external store.
- Serve HTTP and WebSocket from one worker script: branch on `get_dispatcher()->name()`.

## Handshake

**Check `Origin` yourself.** Browsers send cookies with a cross-site handshake and apply no same-origin
policy, so an unchecked `Origin` with cookie auth is cross-site WebSocket hijacking (RFC 6455 §10.2). The
host does not check it, and framework firewalls do not either. A request without `Origin` is not from a
browser.

**Register what the connection needs before `accept()`.** Its handle already exists, and no other unit
of the connection runs until the handshake is answered.

```php
$attachment = json_encode(['v' => 1, 'user' => $userId], JSON_THROW_ON_ERROR);
$connection = $handshake->getConnection();
$presence->add($userId, $connection->getId());
$connection->send('{"type":"welcome"}');         // queued, goes out right after the answer
$handshake->accept($subprotocol, attachment: $attachment);
```

**Undo it yourself if you reject.** A rejected handshake, or one dropped unanswered (the host answers
`500`), gets no `Close`. A worker that dies mid-handshake undoes nothing, so give that state an expiry.

- `accept()` answers `101` over HTTP/1.1 and `200` over HTTP/2 and HTTP/3, then sends what was queued.
- `reject()` answers with any status from `300` to `599` and discards what was queued. A `2xx` would mean
  accepted over HTTP/2 and HTTP/3. Browsers do not follow a redirect here.
- `accept()` rejects `sec-websocket-*` headers with `\ValueError`; the subprotocol has its own parameter.
- `content-length`, `transfer-encoding` and hop-by-hop fields are dropped, so framework response headers
  pass straight through.
- The chosen subprotocol must match an offered one byte for byte, or `accept()` throws `\ValueError`.
  `getRequestedSubprotocols()` is in the header too, but the host parses it once, so no two parsers disagree.
- A browser that offered subprotocols fails a connection accepted with none.
- A client that leaves before the answer turns `isCancelled()` true. `accept()` still succeeds and a
  `Close` with `1006` follows. `reject()` then does nothing.

**Browsers never see a `reject()` status or body.** The page only gets an error and close code `1006`.
To tell the page why, close with a code and reason, then accept with any offered subprotocol:

```php
$offered = $handshake->getRequestedSubprotocols();
$handshake->getConnection()->close(3000, 'sign in first');   // 3000 Unauthorized, 3003 Forbidden, 3008 Timeout
$handshake->accept($offered[0] ?? null);
```

Keep `reject()` for a foreign `Origin` (RFC 6455 §4.2.2 asks for an HTTP error) and for overload.

`getRequest()` is the same `Http\Request` the HTTP plugin hands out, so one hydration path serves both.
Plugins otherwise keep their own shapes; this one is reused because a handshake is an HTTP request.

- `$uri` is `http` or `https`, never `ws` or `wss`, so `isSecure()` holds.
- `$body` is empty. `$target` is the path.
- Over HTTP/2 and HTTP/3, `$method` is `CONNECT`. Show your router `GET`.
- Over HTTP/2 and HTTP/3, `upgrade` and `connection` are absent, and `sec-websocket-key` and `host` usually
  are. Do not branch on them.

## Sending

**`send()` never waits and never throws for a closed connection.** A message to an ended connection is
discarded, as the WHATWG `send()` does. The `Close` unit is the one place you learn a connection ended.

- Text must be valid UTF-8, or `send()` throws `\ValueError`.
- Relay a message as received with `send($message->getPayload(), $message->getType())`.
- The host closes a client whose outbound buffer overflows, with `1008`.

**Replies and relays keep order.** Everything sent while holding a unit of connection X, to X or to any
other connection, goes out before anything sent under X's next unit, whichever worker holds it. Two
messages from one client, relayed to a room, reach every member in the order they were sent.

For slow work, complete first and send later. Sends outside a unit are unordered against later replies:

```php
$connection = $message->getConnection();
$message->complete();                            // the next message is released now
$connection->send($slowService->answer($payload));
```

Pushes from other pools are ordered only among themselves, per worker.

## Connection ids

**Store ids freely; a stale one reaches nobody.**

- Ids are at most 64 bytes of `A-Za-z0-9_-`, carry at least 128 cryptographically random bits and no
  information.
- Ids are never reused, across restarts and hosts. A stored id can go dead but never reaches another client.
- An id is safe to hand to the client, for example to exclude its own connection from a broadcast.
- `get_connection()` accepts any string. An unknown or malformed id discards what it sends.
- `get_connection()` works from every pool the host started, in any mode, for every connection the host holds.
- It does not reach another node behind the same load balancer. Push across nodes through your own broker.
- Call `\Rapira\get_plugins()` first: it lists the plugins this host serves, such as `['http', 'websocket']`.
- Without `websocket` in that list, `get_connection()` throws `NoWebSocketError`.
- A process the host did not start, such as a console command or a supervisor-run consumer, gets an empty
  list.

## Attachment

**Put per-connection state in the attachment, and give it a version.** Workers are replaced on deploy,
connections are not, so a later release may read what an earlier one wrote.

- It is an opaque string, set by `accept()` and replaced by `Message::complete($attachment)`.
- Replacing it is race-free: no other unit of the connection runs meanwhile.
- Every `Message` and `Close` returns it.
- It is never sent to the client, never settable by it and never logged, so an identity can live there unsigned.
- Its size limit is in `rapira.toml`. Over the limit, `accept()` or `complete()` throws `\ValueError` and
  finalizes nothing.

## Close

**Undo per-connection state in the `Close` unit.** Every accepted connection gets exactly one, as its last
unit, whoever ended it.

- It comes at most once, and never when the host process itself dies. Give external state an expiry too.
- A handshake that was never accepted has no `Close`.
- Messages received before the closing handshake are handed out first.
- Data the client sends after its own Close frame is discarded (RFC 6455 §1.4). So is data it sends after
  the host's Close frame, by host policy (RFC 6455 §5.5.1 allows it).
- The WHATWG `error` and `close` events are both this unit. `wasClean()` is false for the error case.

Two codes:

| Method | Returns |
|---|---|
| `getCode()` | The code in the first Close frame received from the client (RFC 6455 §7.1.5), the WHATWG `CloseEvent.code`. |
| `getSentCode()` | The code this side closed with, from a worker or the host. Null when this side only answered. |

- `getCode()` is `1005` when the client's frame had no code and `1006` when no frame arrived.
- After `close(4001)`, `getCode()` is the client's answer: usually `4001`, not always.
- `getSentCode()` shows host closes (limits, idle timeout `1001`, shutdown, a lost message, a protocol
  error such as `1002` or `1007`) that `1006` would hide.
- A `close()` queued before the answer counts, even if the client left before the connection opened.

## Close codes

**`close()` throws `\ValueError` for a code a server may not send.**

- A server may send `1000`–`1003`, `1007`–`1009`, `1011`–`1014` and `3000`–`4999` (RFC 6455 §7.4 and
  the IANA close code registry).
- A browser's `close()` accepts only `1000` and `3000`–`4999`.
- `1010` is client-only (RFC 6455 §7.4.1). `1004` is reserved. `1005`, `1006` and `1015` never go on the wire.
- The rest of `1000`–`2999` is reserved until registered. Registering one is a host update, not a
  signature change.
- A reason is UTF-8, at most 123 bytes.
- `close()` checks its arguments before looking at the connection, so a bad code fails the same way on
  an open or a closed connection. On a connection already closing, a valid `close()` does nothing.

## Lost units

- A handshake dropped without an answer gets `500`.
- A message dropped without `complete()` is a failed handler: the host closes the connection with `1011`.
- A dropped `Close` is only logged.
- The host never finalizes a WebSocket unit itself, so nothing here throws `WorkDiscardedException`.

## Host

What the host does, so PHP never sees it:

- It keeps connections through worker recycling, scale-down and deploys. A worker's fatal drops only the
  connection whose `Message` it held, with `1011`.
- On shutdown it stops taking handshakes, closes every connection with `1001` (`1012` when restarting),
  and hands out their `Close` units before `ClosedException`.
- It routes any unit to any worker of the pool, keeps sends in unit order, and carries `get_connection()`
  from any pool.
- Over HTTP/1.1 it answers a wrong version with `426`, `Sec-WebSocket-Version: 13` and `Upgrade: websocket`.
  A missing or malformed key gets `400`. A non-upgrade request on a WebSocket-only listener gets `426`.
- Over HTTP/2 and HTTP/3 it answers a wrong version with `400` and `Sec-WebSocket-Version: 13`.
- It handles pings, pongs, idle timeouts (closing with `1001`), fragments, masking and permessage-deflate (RFC 7692, configured in
  `rapira.toml`).
- It validates inbound text as UTF-8 after decompression, and closes with `1007` otherwise.
- It accepts from a client the codes a server may send, plus `1010`. Any other code, a one-byte body or a
  non-UTF-8 reason fails the connection, reported as `1006`.
- It enforces `rapira.toml` limits: message size in decompressed bytes (`1009`), messages per second per
  connection (`1008`), attachment size, and the outbound buffer (`1008`).

## Symfony

Checked against Symfony 7.4. The contract has no Symfony or `psr/*` dependency.

- The runtime's runner picks its loop by `get_dispatcher()->name()`.
- Hydrate the handshake into an HttpFoundation `Request` and run it through the kernel: routing, firewall, controller.
- Set `getConnection()` on the request before the controller runs, then map the controller's answer to
  `accept()` or `reject()`.
- `services_resetter` runs between units, as between HTTP requests.
- Later units carry no request or cookies. Rebuild identity from the attachment.
- Controllers and Messenger handlers push with `get_connection()`, after checking `get_plugins()`.

## Evolution

Calling code stays compatible. Hand-written implementations of these interfaces are not covered: the host
is the only implementer, and test doubles come from mocking libraries.

- permessage-deflate, HTTP/2 (RFC 8441) and HTTP/3 (RFC 9220) are host work and `rapira.toml`.
- Every extension the host negotiates is invisible here. Payloads arrive with extensions undone, a message
  is `Text` or `Binary`, and `sec-websocket-extensions` belongs to the host.
- A new handshake decision becomes a trailing optional parameter on `accept()`.
- A new fact on a unit becomes a new method.
- Anything PHP would see differently (a new unit kind, a new `MessageType`, a visible extension) needs two
  steps: `rapira.toml` makes it available, and `accept()` asks for it per connection (RFC 6455 §9.1). A
  worker that never asks never receives it.
- Stubs and extension version separately. Check `\Rapira\get_version()` before passing a newer parameter:
  an unknown named argument is an `\Error`.
- `WebSocketStream` is not in the WHATWG Living Standard, and it reads whole messages too. A message too
  large to hold would arrive spooled to a file, as a new unit kind asked for per connection. Large or paced
  sends would be new methods. `send()` never starts waiting.

## Not in the contract

| Omitted | Why |
|---|---|
| A connection held as one unit, with its own `receive()` | It pins a worker per client and leaves no reset point. |
| WebSocket units on `HttpDispatcher` | It widens `receive(): Exchange` and breaks every HTTP adapter. |
| `Exchange::upgrade()` in the HTTP pool | It ties the two plugins together. The pool that owns connections answers their handshakes. |
| `getConnection()` on `WebSocketDispatcher` | That dispatcher exists only in the `websocket` pool, and pushes mostly start in the HTTP pool. |
| `Handshake extends Http\Exchange` | `writeHead(101)` and `2xx` would have to throw, narrowing what the parent accepts. |
| `accept()` returning the connection | `getConnection()` already hands it out, earlier. |
| An unordered `send()` flag | Completing first already makes later sends unordered. |
| `readyState`, `isOpen()`, `bufferedAmount` | The answer is stale by the next line. |
| `send(): bool`, or an exception on a closed connection | The answer is stale when it returns, and needs a round trip across processes. |
| `sendText()` and `sendBinary()` | Relaying as received would need a `match`. |
| A `CloseCode` enum | Close codes are an open set. |
| `getSentReason()` | A host's reason is log prose. The code is the fact. |
| An array attachment | It crosses processes. One opaque string has one limit. |
| The subprotocol or request on later units | The SDK knows both at `accept()` and can store them in the attachment. |
| The requested extensions | They are in the header, and matter only once `accept()` can choose one. |
| Ping and pong | Keepalive is the host's job. The WHATWG API exposes neither. |
| Subscriber counts or lists | They are stale by the next line and local to one node. |
| Dropping queued messages for a slow client | `send()` never discards on an open connection. The host closes with `1008`. |

Deferred. Each would be a new method, function or trailing parameter, so none breaks a caller:

| Deferred | Waits for |
|---|---|
| Host-side topics (`subscribe()`, `publish()`) | A measured fan-out cost, or a clustered host. |
| A delayed unit inside a connection's order | A case a delayed job calling `close()` cannot cover. |
| A compression switch (RFC 7692 §6, §8) | permessage-deflate in the host. |
| `Connection::sendFile()` | A case a URL sent in a message cannot cover. |
| Host-answered application pings | A measured cost. It would live in `rapira.toml`. |
| One message to many ids in one call | A measured per-call cost. |
| A handshake deadline | The HTTP plugin's deadline decision, which `Request` would carry. |
