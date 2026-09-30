# Rapira contract — WebSocket

Serve WebSocket clients from PHP. The Rapira server keeps every open connection. Your PHP code only
reacts to what happens on a connection, one short job at a time.

Rules shared by all plugins live in the [package README](../../README.md).

## How it works

- The *host* is the Rapira server. It speaks the WebSocket protocol (RFC 6455 and its extensions) and holds
  every client socket.
- A *worker* is one of your PHP processes. The workers that serve WebSocket form the `websocket` *pool*.
- A worker never holds a socket. Each event on a connection reaches some worker as a small job, called a *unit*.

A connection produces these kinds of unit:

| Unit | When it comes | How you answer it |
|---|---|---|
| `Handshake` | A client asks to connect. | Call `accept()` or `reject()`. |
| `Message` | The client sent one message. | Call `complete()`, or `send()` to reply and finish in one call. |
| `Subscribe` | A client asks to join a private [channel](#channels). | Call `allow()` or `deny()`. |
| `Publish` | A client publishes to a private channel. | Call `allow()` or `deny()`. |
| `Close` | The connection ended. It comes exactly once. | Call `complete()`. |

`Subscribe` and `Publish` come only on connections that opted into channels. Answering a unit is called
*finalizing* it. To send something, use the unit's `Connection`.

An echo server:

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
            $unit->send($unit->getPayload(), $unit->getType());
            continue;
        }
        $unit->complete();
    }
} catch (\Rapira\Exception\ClosedException) {
}
```

`receive()` waits for the next unit. `ClosedException` means no more units will ever come. Without
channels, only a `Close` reaches the final `complete()`. With channels, answer `Subscribe` and `Publish`
before it.

## Contract

```php
namespace Rapira\WebSocket;

/** A send handle for any connection, usable from any pool. Any string works; an unknown id sends nowhere. */
function get_connection(string $id): Connection {}

/** Send to every connection on a channel, from any pool. A channel nobody joined is not an error. */
function publish(string $channel, string $data): void {}

interface WebSocketDispatcher extends \Rapira\Dispatcher
{
    public function tryReceive(): Handshake|Message|Subscribe|Publish|Close|null;
    public function receive(int $timeout = -1): Handshake|Message|Subscribe|Publish|Close;
    public function getInfo(): WebSocketDispatcherInfo;   // adds connectionCount()
}

interface Handshake extends \Rapira\Work
{
    public function getRequest(): \Rapira\Http\Request;
    public function getRequestedSubprotocols(): array;    // list<non-empty-string>, client's order
    public function getConnection(): Connection;          // exists before the answer
    public function accept(?string $subprotocol = null, array $headers = [], string $attachment = '', ?string $user = null): void;
    public function reject(int $status = 403, array $headers = [], string $body = ''): void;  // 300–599
}

interface Message extends \Rapira\Work
{
    public function getConnection(): Connection;
    public function getAttachment(): string;
    public function getType(): MessageType;
    public function getPayload(): string;
    public function getReceivedAt(): float;
    public function send(string $payload, MessageType $type = MessageType::Text, bool $complete = true, ?string $attachment = null): void;
    public function complete(?string $attachment = null): void;
}

interface Subscribe extends \Rapira\Work
{
    public function getConnection(): Connection;
    public function getAttachment(): string;
    public function getChannel(): string;
    public function allow(): void;
    public function deny(int $code = 403, string $reason = ''): void;       // 403 or 4000–4999
}

interface Publish extends \Rapira\Work
{
    public function getConnection(): Connection;
    public function getAttachment(): string;
    public function getChannel(): string;
    public function getPayload(): string;
    public function getReceivedAt(): float;
    public function allow(?string $data = null): void;                      // null publishes the payload
    public function deny(int $code = 403, string $reason = ''): void;       // 403 or 4000–4999
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
    public function subscribe(string $channel): void;
    public function unsubscribe(string $channel): void;
    public function close(int $code = 1000, string $reason = ''): void;
}

enum MessageType { case Text; case Binary; }
```

## One unit at a time

**Finalize every unit quickly. The connection's next unit waits until you do.**

- A connection's units arrive in order: the `Handshake`, then its `Message`s, `Subscribe`s and `Publish`es
  as the client sent them, then one `Close`.
- The host hands out a connection's next unit only after you finalize the current one.
- Other connections do not wait for it. They run at the same time on other workers. Within one worker they
  will too, once the host can hold several units per worker.
- Any worker in the pool can get any unit. Two messages from one client may reach two different workers.
- Framework services are reset between units, as they are between HTTP requests.
- The whole connection is never one long unit. That would tie up one worker per connected client, for as
  long as the client stays.

While any unit of a connection is not finalized, the host stops reading from that client, so its next
messages wait.
This is called backpressure. The pause does not count toward the ping or idle timeouts.

**Workers share no memory.**

- Keep per-connection state, such as who the user is, in the [attachment](#attachment).
- Keep shared state, such as chat rooms or who is online, in an external store.

Serve HTTP and WebSocket from one worker script by branching on the dispatcher's name:

```php
$dispatcher = \Rapira\get_dispatcher();

match ($dispatcher->name()) {
    'websocket' => serveWebSocket($dispatcher),
    'http' => serveHttp($dispatcher),
};
```

## Handshake

**Check the `Origin` header yourself. Neither the host nor framework firewalls check it.**

Any website can open a WebSocket to your server, and the browser sends your user's cookies with it.
Browsers do not block cross-site WebSocket connections. If you log users in by cookie and skip this check,
another site can act as your user. The attack is called cross-site WebSocket hijacking (RFC 6455 §10.2).

A request without `Origin` did not come from a browser.

```php
$headers = array_change_key_case($handshake->getRequest()->headers);
$origin = $headers['origin'][0] ?? null;
$isBrowser = $origin !== null;
$isForeign = $isBrowser && $origin !== 'https://example.com';

if ($isForeign) {
    $handshake->reject(403);
}
```

**Set up what the connection needs before you call `accept()`.** Its `Connection` already exists, and no
other unit of the connection runs until you answer the handshake.

```php
$attachment = json_encode(['v' => 1, 'user' => $userId], JSON_THROW_ON_ERROR);
$connection = $handshake->getConnection();
$presence->add($userId, $connection->getId());
$connection->send('{"type":"welcome"}');         // queued, goes out right after the answer
$handshake->accept($subprotocol, attachment: $attachment);
```

**If you reject, undo that setup yourself.** No `Close` unit follows a rejected handshake. None follows a
handshake your code never answered either; the host answers that one with `500`. A worker that dies
mid-handshake undoes nothing, so give that state an expiry.

What `accept()` and `reject()` send:

- `accept()` answers `101` over HTTP/1.1 and `200` over HTTP/2 and HTTP/3. Then it sends what you queued.
- `reject()` answers with any status from `300` to `599` and throws away what you queued.
- `reject()` takes no `2xx` status, because over HTTP/2 and HTTP/3 a `2xx` means accepted.
- Browsers do not follow a redirect from a handshake.
- `accept()` throws `\ValueError` for any `sec-websocket-*` header. Pass the subprotocol as its own parameter.
- The host drops `content-length`, `transfer-encoding` and hop-by-hop headers, so you can pass a framework's
  response headers straight through.

A *subprotocol* is the application protocol the client offers to speak, such as `graphql-ws`.

- The subprotocol you choose must match an offered one byte for byte, or `accept()` throws `\ValueError`.
- Read the offers from `getRequestedSubprotocols()`, not from the header. The host parses the header once,
  so no two parsers can disagree.
- If a browser offered subprotocols and you accept with none, the browser fails the connection.

If the client leaves before you answer, `isCancelled()` turns true. `accept()` still succeeds, and a `Close`
with `1006` follows. `reject()` then does nothing.

**Browsers never show your `reject()` status or body to the page.** The page only gets an error and close
code `1006`. To tell the page why, close with a code and reason, then accept with any offered subprotocol:

```php
$offered = $handshake->getRequestedSubprotocols();
$handshake->getConnection()->close(3000, 'sign in first');   // 3000 Unauthorized, 3003 Forbidden, 3008 Timeout
$handshake->accept($offered[0] ?? null);
```

Use `reject()` only for a foreign `Origin` (RFC 6455 §4.2.2 asks for an HTTP error there) and for overload.

## Handshake request

`getRequest()` returns the same `Http\Request` the HTTP plugin hands out, so one piece of code can turn both
into your framework's request. Plugins normally keep their own types; this one is shared because a
handshake is an HTTP request.

Where it differs from a normal HTTP request:

- `$uri` starts with `http` or `https`, never `ws` or `wss`. A framework's `isSecure()` check works as usual.
- `$body` is empty. `$target` is the path.
- Over HTTP/2 and HTTP/3, `$method` is `CONNECT`. Tell your router it is `GET`.
- Over HTTP/2 and HTTP/3, the `upgrade` and `connection` headers are missing, and `sec-websocket-key` and
  `host` usually are too. Do not branch on them.

## Sending

**`send()` never waits, and never throws because a connection is closed.** A message to a closed
connection is thrown away, as the browser's `WebSocket.send()` does (WHATWG). You learn that a connection
ended only from its `Close` unit.

- Text must be valid UTF-8, or `send()` throws `\ValueError`.
- To relay a message unchanged, call `send($message->getPayload(), $message->getType())`.
- `Message::send()` replies to the client and finalizes the message in one call. Pass `complete: false` to
  keep holding the unit. After the message is finalized it throws `AlreadyFinalizedError`; use
  `getConnection()->send()` from then on.
- If a client reads too slowly and its outgoing buffer fills up, the host closes it with `1008`.

**Messages go out in the order the client's units were handled.** Everything you send while holding a unit
of connection X goes out before anything sent while holding X's next unit. This holds whether you send to X
or to other connections, and whichever worker holds the next unit. So two messages from one client, relayed
to a chat room, reach every member in the order the client sent them.

For slow work, finalize the unit first and send later. A send made outside a unit has no order against
later replies:

```php
$connection = $message->getConnection();
$message->complete();                            // the client's next message is released now
$connection->send($slowService->answer($payload));
```

Sends from other pools, such as an HTTP controller calling `get_connection()`, keep their order only among
themselves, per worker.

## Connection ids

**Store ids anywhere. An id of a closed connection reaches nobody.**

- An id is at most 64 bytes of `A-Za-z0-9_-`.
- It carries at least 128 cryptographically random bits and no other information.
- Ids are never reused, not even across restarts or hosts. A stored id can go dead, but it never reaches a
  different client.
- An id is safe to give to its client, for example so the client can skip its own messages in a broadcast.

Send to an id with `get_connection()`:

- It accepts any string. What you send to an unknown or malformed id is thrown away.
- It works from every pool the host started, in any mode, for every connection the host holds.
- It does not reach connections on another server behind the same load balancer. Send across servers
  through your own message broker.
- Call `\Rapira\get_plugins()` first. It lists the plugins this host serves, such as `['http', 'websocket']`.
- Without `websocket` in that list, `get_connection()` throws `NoWebSocketError`.
- A process the host did not start, such as a console command or a queue consumer run by a supervisor, gets
  an empty list.

To reach a user or a group, publish to a [channel](#channels) instead of storing ids.

## Channels

**Publish by name, such as `room:5` or `user:#42`, and every connection that joined gets it.**

```php
// in the handshake: label the connection and join the user's own channel
$offered = $handshake->getRequestedSubprotocols();
$speaksChannels = in_array('rapira.v1', $offered, true);
$subprotocol = $speaksChannels ? 'rapira.v1' : null;
$handshake->getConnection()->subscribe("user:#{$userId}");
$handshake->accept($subprotocol, attachment: $attachment, user: (string) $userId);

// anywhere: an HTTP controller, a Messenger handler
\Rapira\WebSocket\publish("user:#{$userId}", '{"orderCreated":42}');
```

Turn channels on in two steps:

1. Add a `channels` section to `rapira.toml`.
2. Accept the handshake with the `rapira.v1` subprotocol. The client must offer it.

Without the `channels` section, `rapira.v1` is an ordinary subprotocol name. A client that does not offer it
stays plain WebSocket: PHP can still subscribe it, and it receives each publication as a plain text message.

A `rapira.v1` client can also subscribe, unsubscribe and publish by itself. Who may do that is set per
*namespace*, the part of the name before the first `:` (`room` in `room:5`). A name with no namespace, or
an empty one such as `:x`, belongs to the default one.

| Setting | `subscribe` means | `publish` means |
|---|---|---|
| `private` (default) | PHP decides through a `Subscribe` unit. | PHP decides through a `Publish` unit. |
| `public` | Any client may join. The host answers alone. | Any subscriber may publish. The host relays alone. |
| `off` | Clients cannot join. Only PHP subscribes them. | Clients cannot publish. |

- A name ending in `#<user>`, such as `user:#42`, is user-limited. Only a connection accepted with
  `user: '42'` may join it, and the host decides without asking PHP. `off` still wins.
- A client must join a channel before it can publish there.
- Every subscriber gets a publication, the publisher too.
- A channel name is 1–255 bytes of `A-Za-z0-9_-.:#@`. `publish()`, `subscribe()` and `unsubscribe()` throw
  `\ValueError` for any other name.
- The channel limit per connection in `rapira.toml` refuses client subscribes with `429`. Subscribes made by
  PHP count toward it, but are never refused.

From PHP:

- `publish($channel, $data)` works from every pool the host started, like `get_connection()`, and throws
  `NoWebSocketError` where that does. It never waits, and a channel nobody joined is not an error.
- `$data` must be valid UTF-8, or `publish()` throws `\ValueError`.
- `$connection->subscribe()` and `unsubscribe()` never wait and do nothing on a closed connection.
  `unsubscribe()` also does nothing for a channel the connection never joined.
- Made during a handshake, `subscribe()` and `unsubscribe()` wait for the answer, and `reject()` throws them
  away.
- Publications keep the send order: under a unit of connection X, they go out before anything sent under
  X's next unit. From other pools they keep their order only among themselves, per worker.
- `Publish::allow()` sends the publication after anything already sent under that unit.
- Channel commands the host answers alone come after the sends of the connection's previous unit.
- Channels live on one server. A publication does not reach connections on another server behind the same
  load balancer.

The *user label* is the `$user` argument of `accept()`, usually your user id: 1–128 bytes of
`A-Za-z0-9_-.@`. The host shows it in logs and metrics and checks it for `#` channels. Later units do not
carry it; keep what PHP needs in the attachment.

Answer a `Subscribe` with `allow()` or `deny()`, and a `Publish` the same way. `Publish::allow($data)` can
replace what gets published. `deny()` takes `403` or a code of your own from `4000` to `4999`:

```php
$isMember = $rooms->isMember($subscribe->getChannel(), $userId);
if (!$isMember) {
    $subscribe->deny(4004, 'not a member');
    return;
}
$subscribe->allow();
```

## Channel wire format

What a `rapira.v1` client sends and receives. Every text frame is one JSON object with exactly one command
key. Unknown extra keys are ignored. Binary frames pass through unwrapped, as plain messages.

Client to host:

```json
{"id": 1, "subscribe": "room:5"}
{"id": 2, "unsubscribe": "room:5"}
{"id": 3, "publish": "room:5", "data": "hello room"}
{"message": "hello server"}
```

Host to client:

```json
{"id": 1, "ok": true}
{"id": 3, "error": {"code": 4004, "reason": "not a member"}}
{"channel": "room:5", "data": "hello room"}
{"subscribed": "user:#42"}
{"unsubscribed": "room:5"}
{"message": "hello client"}
```

- `{"message": ...}` from the client becomes a `Message` unit, with the string as its payload.
  `Connection::send()` text goes out the same way.
- `data` and `message` are always JSON strings. Send JSON inside them as text.
- `id` is optional: a string or an integer, echoed back as sent. The host replies only to `subscribe`,
  `unsubscribe` and `publish` commands that carry one. `message` takes no `id` and never gets a reply.
- `subscribed` and `unsubscribed` report changes PHP made.
- The `ok` for a subscribe goes out before the channel's first publication.
- Subscribing twice is `ok`.
- Commands count toward the messages-per-second limit. The message size limit applies to the whole frame.
- A text frame that is not a JSON object closes the connection with `1002`.

| Error code | Meaning |
|---|---|
| `400` | The frame is not a valid command: no known key, more than one, a missing or wrong-typed value, or an invalid channel name. |
| `403` | Refused. It means the same whoever refused: the host (`off`, a `#` channel of another user) or PHP's `deny()`. |
| `404` | Unsubscribing or publishing on a channel the connection has not joined. |
| `429` | The connection is at its channel limit. |
| `500` | PHP let go of the `Subscribe` or `Publish` without answering it. |
| `503` | The `Subscribe` or `Publish` waited longer than the queue timeout. |
| `4000`–`4999` | The application's own reason, from `deny()`. |

The connection stays open after every error.

## Attachment

The *attachment* is a string the host stores with a connection and gives back with every unit.

**Keep per-connection state in the attachment, and put a version number in it.** Deploys replace workers
but keep connections open, so a newer release may read what an older one wrote.

- Set it with `accept()`. Replace it with `Message::complete($attachment)`, or with the `$attachment`
  parameter of `Message::send()` when that call finalizes.
- Replacing it is safe: no other unit of the connection runs at the same time.
- Every unit after the handshake returns it.
- It is never sent to the client, the client cannot set it, and it is never logged. You can keep an
  identity there without signing it.
- Its size limit is set in `rapira.toml`. Over the limit, `accept()` or `complete()` throws `\ValueError`
  and does not finalize the unit.

## Close

**Clean up per-connection state in the `Close` unit.** Every accepted connection gets exactly one, as its
last unit, no matter which side ended it.

- It never comes if the host process itself dies. Give external state an expiry as well.
- A handshake that was never accepted gets no `Close`.
- Messages, subscribes and publishes the client sent before it started closing are handed out first, with
  `isCancelled()` true. Answering them still succeeds; replies are dropped.

To close, each side sends a Close frame, and the other side answers with one.

- Data the client sends after its own Close frame is thrown away (RFC 6455 §1.4).
- Data the client sends after the host's Close frame is thrown away too. That is host policy, which RFC 6455
  §5.5.1 allows.
- The browser's `error` and `close` events both end up as this unit. For the error case, `wasClean()` is false.

A `Close` carries two codes:

| Method | Returns |
|---|---|
| `getCode()` | The code in the first Close frame the client sent (RFC 6455 §7.1.5). The browser sees the same value as `CloseEvent.code`. |
| `getSentCode()` | The code this side closed with, whether your code or the host closed. It is null when only the client closed. |

- `getCode()` is `1005` when the client's frame had no code, and `1006` when no frame arrived.
- After you call `close(4001)`, `getCode()` is the client's answer. That is usually `4001`, but not always.
- `getSentCode()` shows why the host closed, where `getCode()` would only say `1006`. Examples: a limit, the
  idle timeout (`1001`), the queue timeout (`1013`), a shutdown, a unit that was never finalized, or a
  protocol error such as `1002` or `1007`.
- A `close()` queued before the handshake answer counts, even if the client left before the connection opened.

## Close codes

**`close()` throws `\ValueError` for a code a server may not send.**

| Codes | Who may send them |
|---|---|
| `1000`–`1003`, `1007`–`1009`, `1011`–`1014`, `3000`–`4999` | A server (RFC 6455 §7.4 and the IANA close code registry). |
| `1000`, `3000`–`4999` | A browser's `close()`. |
| `1010` | Only a client (RFC 6455 §7.4.1). |
| `1004` | Nobody. It is reserved. |
| `1005`, `1006`, `1015` | Nobody. They are never sent, only reported. |
| The rest of `1000`–`2999` | Nobody, until registered. |

A newly registered code arrives with a host update. The method signature stays the same.

- A reason is UTF-8 and at most 123 bytes.
- `close()` checks its arguments before it looks at the connection. A bad code fails the same way on an open
  or a closed connection.
- On a connection that is already closing, a valid `close()` does nothing.

## Unfinalized units

What the host does when your code lets go of a unit without finalizing it:

- A `Handshake` gets a `500` answer.
- A `Message` counts as a failed handler. The host closes the connection with `1011`.
- A `Subscribe` or `Publish` gets error `500`. The connection stays open, because the client has a reply to
  read the failure from.
- A `Close` is only logged.

The host never finalizes a WebSocket unit on its own, so nothing here throws `WorkDiscardedException`.

## Host

What the host handles, so PHP never sees it:

- It keeps connections open while workers restart, scale down or get replaced on deploy. A fatal error in a
  worker drops only the connection whose `Message` that worker held, with `1011`. A `Subscribe` or
  `Publish` it held gets error `500`, and that connection stays open.
- On shutdown it stops accepting handshakes and closes every connection with `1001`, or `1012` when
  restarting. It hands out their `Close` units before `ClosedException`.
- It routes any unit to any worker of the pool, keeps sends in unit order, and lets any pool use
  `get_connection()` and `publish()`.
- It answers public and user-limited channel commands itself, in the order the client sent them.
- A unit that waits for a free worker longer than the queue timeout in `rapira.toml` is never handed out:
  - A waiting `Handshake` gets `503`, and no `Close` follows.
  - A waiting `Message` closes the connection with `1013` (try again later), and its `Close` follows.
  - A waiting `Subscribe` or `Publish` gets error `503`, and the connection stays open.
  - A `Close` never times out, so cleanup always runs.
- Over HTTP/1.1 it answers a wrong protocol version with `426`, `Sec-WebSocket-Version: 13` and
  `Upgrade: websocket`. A missing or malformed key gets `400`. A plain HTTP request on a WebSocket-only
  listener gets `426`.
- Over HTTP/2 and HTTP/3 it answers a wrong protocol version with `400` and `Sec-WebSocket-Version: 13`.
- It handles pings and pongs, idle timeouts (closing with `1001`), fragmented messages, masking, and
  permessage-deflate compression (RFC 7692, configured in `rapira.toml`).
- It checks that incoming text is valid UTF-8 after decompression, and closes with `1007` if not.
- From a client it accepts every code a server may send, plus `1010`. Any other code, a one-byte Close body
  or a reason that is not UTF-8 fails the connection, which is reported as `1006`.
- It enforces the limits in `rapira.toml`:
  - message size, counted after decompression (closes with `1009`)
  - messages per second per connection (closes with `1008`)
  - attachment size
  - the outgoing buffer (closes with `1008`)
  - channels per connection (refuses client subscribes with `429`)

## Symfony

Checked against Symfony 7.4. The contract itself depends on neither Symfony nor any `psr/*` package.

- The runtime's runner picks its loop by `get_dispatcher()->name()`.
- Turn the handshake into an HttpFoundation `Request` and run it through the kernel: routing, firewall, controller.
- Put `getConnection()` on the request before the controller runs. Then turn the controller's response into
  `accept()` or `reject()`.
- `services_resetter` runs between units, as it does between HTTP requests.
- Later units carry no request and no cookies. Rebuild the user's identity from the attachment.
- Controllers and Messenger handlers send with `publish()` or `get_connection()`, after checking
  `get_plugins()`.

## Future changes

**Code that calls this contract keeps working.** Classes you write yourself that implement these interfaces
are not covered. The host is the only implementer, and test doubles should come from a mocking library.

- HTTP/2 (RFC 8441), HTTP/3 (RFC 9220) and permessage-deflate are host work, switched on in `rapira.toml`.
- Protocol extensions the host agrees with the client stay invisible here. Payloads arrive already decoded,
  a message is `Text` or `Binary`, and the `sec-websocket-extensions` header belongs to the host.
- A new decision at handshake time becomes a new optional last parameter of `accept()`.
- A new fact about a unit becomes a new method.
- Anything PHP would see differently, such as a new unit kind, a new `MessageType` or a visible extension,
  needs two steps: `rapira.toml` makes it available, and `accept()` asks for it per connection
  (RFC 6455 §9.1). A worker that never asks never receives it.
- This package and the host's PHP extension have separate versions. Check `\Rapira\get_version()` before
  passing a newer parameter: an unknown named argument throws `\Error`.
- Messages always arrive whole. The browser's `WebSocketStream` API is not in the WHATWG standard, and it
  reads whole messages too.
- A message too large to hold in memory would arrive saved to a file, as a new unit kind that a connection
  asks for.
- Sending large or paced data would come as new methods. `send()` will never start waiting.

## Not in the contract

| Left out | Why |
|---|---|
| A whole connection as one unit, with its own `receive()` | It would tie up a worker per client and leave no point to reset services. |
| WebSocket units on `HttpDispatcher` | It would widen `receive(): Exchange` and break every HTTP adapter. |
| `Exchange::upgrade()` in the HTTP pool | It would tie the two plugins together. The pool that owns the connections answers their handshakes. |
| `getConnection()` on `WebSocketDispatcher` | That dispatcher exists only in the `websocket` pool, and most sends start in the HTTP pool. |
| `Handshake extends Http\Exchange` | `writeHead(101)` and any `2xx` would have to throw, which narrows what the parent accepts. |
| `accept()` returning the connection | `getConnection()` already hands it out, and earlier. |
| A flag for unordered `send()` | Finalizing the unit first already makes later sends unordered. |
| `readyState`, `isOpen()`, `bufferedAmount` | The answer is out of date by the next line. |
| `send(): bool`, or an exception on a closed connection | The answer is out of date when it returns, and it needs a round trip between processes. |
| `sendText()` and `sendBinary()` | Relaying a message unchanged would then need a `match`. |
| A `CloseCode` enum | New close codes keep being registered. |
| `getSentReason()` | The host's reason is log text. The code is the fact. |
| An array attachment | It crosses processes. One opaque string has one size limit. |
| The subprotocol or request on later units | You know both at `accept()` and can store them in the attachment. |
| The extensions the client asked for | They are in the header, and matter only once `accept()` can choose one. |
| Ping and pong | Keeping the connection alive is the host's job. The browser API exposes neither. |
| Subscriber counts or lists | They are out of date by the next line, and cover only one server. |
| `getUser()` on units | The SDK can keep the user in the attachment. The label exists only because the host checks `#` channels and logs it. |
| JSON values as `data` and `message` | `send()` would need a JSON check that depends on the connection, and relaying between plain and `rapira.v1` clients would break. |
| An attachment on `allow()` and `deny()` | No need yet. It would be a new optional last parameter. |
| Dropping queued messages for a slow client | `send()` never throws away a message on an open connection. The host closes the client with `1008` instead. |

Postponed. Each would be a new method, function or optional last parameter, so none breaks existing code:

| Postponed | Waits for |
|---|---|
| Publishing across servers | A clustered host, or a broker in `rapira.toml`. |
| Channel history and recovery after a reconnect | A case that reloading state over HTTP cannot cover. |
| Presence: who is on a channel, join and leave events | A measured need. Subscriber lists are left out for now. |
| Changing the user label after the handshake | A client that logs in by message. It would be a `$user` parameter on `Message::complete()`. |
| A JavaScript client for `rapira.v1` | Demand. The wire format above is the contract. |
| Handling one connection's messages concurrently | A measured need. It would be a `rapira.toml` option, off by default, like Centrifugo's `client.concurrency`. |
| A delayed unit inside a connection's order | A case that a delayed job calling `close()` cannot cover. |
| A compression switch (RFC 7692 §6, §8) | permessage-deflate in the host. |
| `Connection::sendFile()` | A case that sending a URL in a message cannot cover. |
| Application pings answered by the host | A measured cost. It would be set in `rapira.toml`. |
| A handshake deadline | The HTTP plugin's decision on deadlines, which `Request` would carry. |
