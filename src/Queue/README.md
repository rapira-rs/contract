# Rapira contract — Queue

The shared core — `Dispatcher`, `Work`, the rules every plugin obeys — lives in the
[package README](../../README.md); this file holds only what is the queue's own.

Host-side one plugin speaks to every broker it has a driver for — RabbitMQ over AMQP, Kafka, NATS core
and JetStream, Redis streams and lists, SQS, and an in-process queue — and owns everything the brokers
do differently: acknowledgement, lease renewal, offset commits, delayed redelivery, dead-lettering.
None of that reaches PHP. The worker sees a **pipeline** — one named subscription on one broker, with
its own policy for attempts, backoff, ordering and where a failed message goes — and deliveries
arriving through it, each settled by one of three verbs.

```php
namespace Rapira\Queue;

interface QueueDispatcher extends \Rapira\Dispatcher
{
    public function tryReceive(): ?Delivery;

    public function receive(int $timeout = -1): Delivery;

    public function getInfo(): QueueDispatcherInfo;

    /** Resolved from `rapira.toml` at boot: the adapter refuses to start on one it cannot handle.
     *  @return list<PipelineInfo> */
    public function getPipelines(): array;
}

/** One delivery: the message, this delivery of it, and the verbs that settle it. */
interface Delivery extends \Rapira\Work
{
    public function getMessage(): Delivery\Message;

    public function getContext(): Delivery\Context;

    public function complete(): void;

    /** Null takes the pipeline's backoff; microseconds, as `receive()` counts them. */
    public function retry(?int $delay = null): void;

    /** Final. Where the message goes next is the pipeline's policy. */
    public function fail(Failure $failure = new Failure()): void;
}

/** Why it failed: to the host's log, and onto the dead-letter wherever the host publishes it. */
final readonly class Failure
{
    public function __construct(
        public string $reason = '',
        public array $details = [],   // array<string, string> — an error code, the field that failed
    ) {}
}

final readonly class PipelineInfo
{
    public function __construct(
        public string $name,
        public Driver $driver,        // amqp | kafka | nats | jetstream | redis-stream | redis-list | sqs | memory
        public array $sources,        // list<string> — queues, topics, subject patterns, keys, as configured
        public Ordering $ordering,    // unordered | per-key | total
        public Guarantee $guarantee,  // at-most-once | at-least-once
    ) {}
}

interface QueueDispatcherInfo extends \Rapira\DispatcherInfo
{
    /** @return array<string, QueueDispatcherInfo\PipelineCounters> Every pipeline present. */
    public function byPipeline(): array;
}
```

```php
namespace Rapira\Queue\QueueDispatcherInfo;

/** Where one pipeline's messages wait: in the broker, in the host, in this worker. */
final readonly class PipelineCounters
{
    public function __construct(
        public ?int $backlog,   // not yet fetched by the host; null: unknown right now, never "empty"
        public int $pending,    // fetched, held by the host — withheld-for-order included
        public int $active,     // handed to this worker, unfinalized
    ) {}
}
```

The shapes only a delivery hands out live under its name:

```php
namespace Rapira\Queue\Delivery;

/** What the producer sent: the same on every attempt. */
final readonly class Message
{
    public function __construct(
        public ?string $id,           // the message's own identity; null where it carries none — always on Kafka
        public string $destination,   // the concrete topic / subject / key / queue it was sent to; AMQP: routing key
        public ?string $key,          // ordering key: Kafka record key, SQS MessageGroupId
        public ?string $body,         // null is no body — a Kafka tombstone — not an empty one
        public Headers $headers,
        public ?float $publishedAt,   // unix timestamp; null where the broker records none
    ) {}
}

/** Multivalued, keys case-sensitive, values bytes — typed broker values coerced by one rule. */
final readonly class Headers implements \Countable, \IteratorAggregate
{
    /** @param array<non-empty-string, non-empty-list<string>> $entries */
    public function __construct(public array $entries = []) {}

    /** @return list<string> */
    public function values(string $name): array {}
}

/** This delivery of it: new on every attempt. */
final readonly class Context
{
    public function __construct(
        public string $pipeline,
        public int $attempt,          // 1 on the first; never less than 1 + the retries asked for
        public bool $redelivered,     // an earlier attempt may have run unsettled — its effects may exist
        public float $receivedAt,
        public ?float $deadline,      // the pipeline's processing limit; the lease is renewed up to it
        public ?Origin $origin,       // the broker's own coordinates, a class per driver; null on Redis lists, memory
    ) {}
}
```

What belongs to one broker lives under the broker's name, beside the others of its kind; every
origin implements the empty `Delivery\Origin`:

```php
namespace Rapira\Queue;

interface Delivery\Origin {}

final readonly class Amqp\AmqpOrigin { /* exchange, correlationId, replyTo, type, contentType, …, the typed headers table */ }
final readonly class Kafka\KafkaOrigin { /* partition, offset */ }
final readonly class Nats\NatsOrigin { /* replyTo */ }
final readonly class Nats\JetStreamOrigin { /* stream, consumer, streamSequence, consumerSequence, pending */ }
final readonly class Redis\RedisStreamOrigin { /* entry id */ }
final readonly class Sqs\SqsOrigin { /* deduplicationId, sequenceNumber */ }
```

The adapter's whole dispatch is a lookup and a verb:

```php
$message = $delivery->getMessage();
$context = $delivery->getContext();
try {
    $router->route($message, $context)->handle($message, $context);
    $settle = $delivery->complete(...);
} catch (Reject $e) {
    $settle = fn() => $delivery->fail(new Failure($e->getMessage()));
} catch (RetryLater $e) {
    $settle = fn() => $delivery->retry($e->delay);
} catch (\Throwable) {
    $settle = $delivery->retry(...);
}
try {
    $settle();
} catch (\Rapira\Exception\WorkDiscardedException) {
    // the host closed it first — lease lost, deadline passed
}
```

The verb runs outside the handler's `try`: a verb thrown into a `catch (\Throwable)` would be answered
with a second verb, and the second one throws too. An unknown error retries: `fail()` is final, and a
lost database connection is not a verdict on the message — the pipeline's maximum attempts turns a
persistent one into a failure anyway.

- A delivery is one type with three verbs, and the verbs are the queue's, never a broker's. Each has
  a spelling on every driver — native where the broker has the operation, carried out by the host
  where it has not:

  | Verb | Native | The host carries it out |
  |---|---|---|
  | `complete()` | AMQP `ack`, JetStream `Ack`, Redis `XACK`/`LREM`, SQS `DeleteMessage`; Kafka commits up to the lowest offset still unfinalized | NATS core, memory: nothing to acknowledge |
  | `retry()` | JetStream `Nak` with delay, SQS `ChangeMessageVisibility`; Kafka share groups `RELEASE`, which takes no delay | AMQP, Kafka consumer groups, Redis: republish to a delay queue or retry topic, then acknowledge — on an ordered pipeline, hold the original unacknowledged through the delay; a share group's delay: hold, then `RELEASE`; NATS core, memory: held in memory |
  | `fail()` | AMQP `reject` without requeue, into the dead-letter exchange; dropping: JetStream `Term`, Kafka share groups `REJECT` | every driver, to a dead-letter pipeline: publish there, then settle the original — `ack`, `Term`, `REJECT`, delete or commit; NATS core, memory, with no dead-letter pipeline configured: drop |

  So no verb is conditional on the driver, and no driver leaks its vocabulary into the handler: `ack` /
  `nack(requeue)` is AMQP's split, and on Kafka neither exists.
- The message and the delivery are two objects because they change at different times. `Message` is
  what the producer sent — where it sent it included — and a retry the host republishes carries it
  forward field for field; `Context` is this attempt — its number, its deadline, the broker
  coordinates of the copy in hand. Both are data, so handing them down grants reading and never
  settling, the privilege split gRPC draws between `Call` and `Responder` without a second interface:
  a queue handler has no answering side that could be granted alone.
- Nothing names a job. A broker has no notion of a job's kind — AMQP has an optional `type`, NATS has a
  subject, Kafka has only header conventions — and a topic filled by another system carries none of
  them. Routing is the SDK's, on `$destination`, a header or the body, whichever its producers agreed
  on.
- `$destination` is on `Message` because it is the producer's fact: a retry republished to a delay
  queue or a retry topic still reports the topic the producer wrote to, so a router keyed on it
  routes the second attempt where it routed the first. It is the concrete name, `PipelineInfo::$sources`
  the configured one: a pipeline subscribed to `orders.*` or a topic regex receives from many names,
  and the one a message was sent to is often the only routing fact it carries. AMQP is the exception
  to "one of the sources": the producer addresses an exchange and a routing key, the pipeline consumes
  a queue, and the bindings between them are the broker's.
- `$id` is null where the message has no identity of its own, because a stable id cannot be made up:
  a Kafka offset changes when a retry is republished, an AMQP delivery tag dies with its channel. The
  broker's coordinates are on `$origin`, where they mean exactly what the broker says.
- Two facts about repetition, never one. `$attempt` is the count the pipeline's maximum is checked
  against: exact where the host republishes retries with its own counter, the broker's delivery count
  where the driver retries in place, and so never less than the retries asked for — save on an
  ordered pipeline over AMQP, Kafka consumer groups or Redis, where the host holds a retry in place,
  counts it in memory, and a host restart starts the count over. `$redelivered` is
  the at-least-once warning — an earlier attempt may have reached a worker and never been settled, so
  its side effects may exist. A retry is a decision, a redelivery is a crash; an idempotency check
  wants the second. Neither answer is proof, and each driver errs its own way: a Redis stream entry
  is redelivered when the host reads it back from a pending list — its own after a restart, another
  consumer's by a claim; JetStream, SQS and Kafka share groups count a retry and a crash alike, and on
  an ordered pipeline a held retry comes back as a redelivery after a host restart, so in both cases
  the host subtracts the retries it recorded and answers true once a restart has lost that record;
  Kafka consumer groups re-read uncommitted offsets after a rebalance with no trace of what was
  processed, so there false can miss one.
- Where a driver retries in place and the broker keeps an attempt limit of its own — JetStream's
  `MaxDeliver`, an SQS redrive policy's `maxReceiveCount`, a share group's delivery count limit — it
  counts the same deliveries `$attempt` does, and the lower limit ends the message; the broker's
  ending skips the pipeline's dead-letter policy, so the host refuses at boot a pipeline whose maximum
  exceeds its broker's. A quorum queue's `delivery-limit` is not one: the host republishes AMQP
  retries, and each copy starts its own count, so that limit bounds crashes per copy.
- The lease is the host's. Ack wait, visibility timeout, acquisition lock, a Redis claim — every broker
  that leases a message takes it back when the consumer goes quiet, and most let the consumer renew.
  PHP is embedded in the process that holds the lease, so the host knows the worker is alive better
  than any heartbeat the worker could send: it renews while the delivery is unfinalized, up to
  `$deadline`. Where the broker caps how long a lease may be held — RabbitMQ's consumer timeout, SQS's
  twelve hours from receipt, a Kafka share group's acquisition lock on a broker that cannot renew
  it — `$deadline` is capped with it, so a null deadline means no limit
  anywhere. A lease lost anyway — a Kafka rebalance, a refused renewal — is `isCancelled()`, a verb
  after it is `WorkDiscardedException`, the `Work` discipline unchanged, and the broker redelivers the
  message elsewhere on its own.
- Order is enforced at `receive()`, not described. On an ordered pipeline the host withholds a message
  while its predecessor — under the same key for `PerKey`, at all for `Total` — is held unfinalized by
  any worker of the host, so deliveries in flight at once — on several workers, or on several fibers
  of one — cannot reorder a key. A
  retry on such a pipeline keeps its place and the messages behind it wait: a pipeline that would
  rather let them pass is one configured `Unordered`. The place survives a host restart because on an
  ordered pipeline the host never republishes a retry — it holds the original unacknowledged through
  the delay, and a restart hands it back first. The price is the delay: it is cut to how long the
  broker lets a message be held — the same caps `$deadline` carries — since past it the
  broker takes the message back on its own terms. A null key orders with nothing — a Kafka record
  without a key was never promised an order by its producer. The promise is one host's; a second host
  on the same pipeline keeps it only where the broker gives each key to one consumer, as Kafka does a
  partition to one consumer group member, SQS a FIFO group to one receiver, RabbitMQ a queue to its
  single active consumer. The host refuses at boot what it can see is unorderable: an ordered pipeline
  on a Kafka share group or an SQS standard queue, `PerKey` on a driver whose messages carry no key.
  Kafka's offset bookkeeping needs no such care — the host commits up to the lowest offset still
  unfinalized, so settling out of order is always safe.
- `Guarantee` is on the descriptor because it decides what a handler may do, and only the
  configuration knows it: the same Redis list is at-least-once through a processing list and
  at-most-once without one. On an at-most-once pipeline the verbs still finalize, `retry()` is held in
  host memory and `fail()` goes wherever policy says — the same surface, with what a crash costs
  stated at boot.
- `$body` is nullable and `$key` stands apart, because Kafka makes both facts real: a tombstone is a
  record whose value is null, meaning "deleted" in a compacted topic, and the key is data, not only
  routing. The same key is the SQS FIFO message group, so `Ordering::PerKey` orders by one field
  whatever the broker. A Redis stream entry is a field map with no body at all: the pipeline names the
  field that is the body, and the rest arrive as headers.
- Headers are bytes, multivalued and case-sensitive, because that is the intersection every broker
  keeps: Kafka repeats keys and allows binary values, NATS and Redis streams repeat keys, and no broker
  folds case.
  Typed values — AMQP tables, SQS `Number` — become bytes by one fixed rule, so a header reads the same
  whichever driver delivered it. AMQP's arrays and nested tables have no bytes to become, so they are
  absent from `Headers` and present, typed, in `Amqp\AmqpOrigin::$table`, where `x-death` lives. `Headers`
  lives under `Delivery\` beside `Message`, the only shape that hands it out.
- Broker coordinates are a closed union of final classes, one per driver, rather than an attribute
  map: an SDK `match`es the origin it knows, and each field means one thing on one broker. The cost is
  that a driver is a contract addition — a class under the broker's namespace, a union arm, a `Driver`
  case — and widening the union breaks every exhaustive `match` downstream, which is the cost of the
  fact being in the type. The `Delivery\Origin` marker is a name for code that handles any origin, not
  a replacement for the union: typed as the interface alone, the field would be an open set no
  analyser can check a `match` against, since PHP has no sealed interfaces. An origin carries only
  what no shared field does: JetStream's `num_delivered` and SQS's `ApproximateReceiveCount` are
  `$attempt`, not a second copy of it.
- A pipeline's counters are one object, not a map per counter. Backlog and pending are the same word
  in plain English — waiting — and differ only in where: in the broker, or fetched into the host. A
  field each, with its own definition, says that where two method names could not; and the three
  places a message waits read together for one pipeline instead of from parallel maps sharing keys.
  The three never overlap: Kafka's backlog runs from the host's fetch position, not from the
  committed offset, which trails behind everything pending and active.
- `fail()` takes data, as `Grpc\Responder::fail()` takes a `Status`: the worker says why, the host
  carries it wherever it publishes the dead-letter itself — every driver but a policy that leaves the
  dead-lettering to AMQP's exchange, whose `x-death` holds only an enumerated reason. A class rather
  than a string, so what a replay tool needs beyond the reason lands in `$details` without changing
  the verb.
- Delays are microseconds because `receive()` counts in microseconds: one unit for every duration the
  contract spells. `retry()` takes any delay on any driver; where the broker has no delayed
  redelivery or caps it — SQS stops at twelve hours — the host schedules the rest.

## Exceptions

The queue adds no exception types. The verbs throw the shared pair — `AlreadyFinalizedError` for a
second verb, `WorkDiscardedException` for a verb after the host closed the delivery — and nothing else
a worker would catch. A delivery left unfinalized — dropped, past its `$deadline`, held when the worker
drains — is retried per policy, as `retry()` without a delay would do, and the attempt counts. One
escaping throwable is a bug: the script fatals, the worker is recycled per pool policy, and the
delivery is retried. A message that keeps crashing its worker exhausts its attempts and fails — the
handler's crash is not the queue's data loss, and a poison message is not an infinite loop. The
exception is a crash that takes the host down with it: on AMQP, Kafka consumer groups and Redis the
attempt count is the host's, and the message comes back at the attempt it crashed on.

## Not in the contract

| Omitted | Why |
|---|---|
| `ack()` / `nack(bool $requeue)` / `reject()` | AMQP's verbs; Kafka has none of them, NATS core has nothing to acknowledge. `complete()`, `retry()` and `fail()` have a spelling on every driver |
| a job name on the message | no broker has one; the SDK routes on `$destination`, a header or the body, per its producers' convention — computable, so not contract |
| `touch()` / `extendLease()` / JetStream `InProgress` | the host renews every lease while the delivery is unfinalized; a heartbeat from the worker tells the process holding the lease what it already knows |
| a lease deadline apart from `$deadline` | renewed leases never expire under a live worker, and a broker's cap on renewal is already folded into `$deadline` |
| `receiveMany()` / batch acknowledgement | a `tryReceive()` loop is the batch; per-delivery verbs keep outcomes independent, and Kafka's batched commits are the host's bookkeeping |
| `retryAt(float $unixTimestamp)` | `($at - microtime(true)) * 1_000_000` |
| a `Throwable` or a stack trace in `Failure` | the trace is the worker's log; a broker header has size limits a trace runs into, and a `Throwable` does not cross into the host |
| a `$code` on `Failure` | no queue protocol standardizes one, so a code is one key of `$details` — one spelling for the fact |
| separate `Driver` cases for Kafka consumer and share groups | what differs for a handler — ordering, how attempts are counted — is on `Ordering`'s boot check and `$attempt`; the rest is the host's |
| a `$contentType` on `Message` | only AMQP carries one natively — `Amqp\AmqpOrigin::$contentType`; elsewhere it is a header by convention, and decoding is the producer's agreement with the handler |
| an attribute map in place of the per-broker origin classes | a map says neither which keys exist nor what they mean per broker; a type says both |
| `Sqs\SqsOrigin::$receiveCount`, a delivery count on `Nats\JetStreamOrigin` | each is `$attempt` on its driver; one fact, one field |
| delivery tags, receipt handles, ack subjects | valid only to the connection or channel that holds them, which is the host's |
| committing Kafka offsets, seeking, pausing a partition | a worker settles deliveries; the log position is the host's, derived from what is settled |
| exactly-once processing | consume-transform-produce needs the produce side in the same transaction; this contract only consumes |
| request–reply — answering `Nats\NatsOrigin::$replyTo` or AMQP's `replyTo` | a caller waiting on a reply, with a timeout and no retry, is RPC and not a queue; `$replyTo` stays data |
| `Delivery\Context::timeRemaining()` | `$deadline - microtime(true)` |
| `Headers::has()` | `values($k) !== []` |
| reusing `Grpc\Metadata` for headers | its keys are case-insensitive and its `-bin` suffix is gRPC law; broker headers are neither |
| the connection a pipeline runs on, on `PipelineInfo` | host topology, not a fact any handler branches on |

## Open

1. **Producing from PHP.** Publishing is the most common thing PHP does with a queue — an HTTP handler
   enqueues, a queue handler emits the next step — and it is where the brokers differ most: a Kafka key
   and partition, SQS group and deduplication ids, AMQP exchange, routing key and publisher confirms,
   JetStream's `Nats-Msg-Id`, a delay on publish. A producer is not a stream of work units, so it waits
   on the package's own open question — the acquisition path for non-dispatcher plugins — and a pool
   serving HTTP must reach it without a `QueueDispatcher`. The outbound message is its own type:
   `Delivery\Message` holds what only a delivery has, `$publishedAt` among it.
