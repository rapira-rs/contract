<?php

declare(strict_types=1);

namespace Rapira\Http;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Work;

/**
 * One HTTP request/response exchange: the request data plus the verbs that answer it.
 *
 * The response is written, never returned. The head commits once — explicitly via
 * {@see self::writeHeader()}, or implicitly as `200` on the first body write — then body chunks follow
 * until the response ends, either on a chunk carrying `$eos` or on {@see self::writeTrailers()}. That
 * ending finalizes the exchange.
 *
 * ```php
 * $exchange->writeBody($html);                    // 200, one shot, one write on the wire
 *
 * $exchange->writeHeader(200, ['content-type' => ['text/event-stream']]);
 * $exchange->flush();                             // the client sees the head before event one
 * foreach ($events as $event) {
 *     $exchange->writeBody($event, eos: false);   // each body write reaches the wire
 * }
 * $exchange->writeBody('', eos: true);
 * ```
 *
 * An atomic `respond(Response)` is not here on purpose: it cannot express a response whose head must
 * reach the client before the first body byte exists, while these primitives express both. The
 * ergonomic atomic API belongs in the SDK, built on top.
 */
interface Exchange extends Work
{
    public function getRequest(): Request;

    /**
     * Commit the response head: fix the status and headers, and close the window for
     * {@see self::sendEarlyHints()}. Optional — the first {@see self::writeBody()} commits `200` with
     * no headers.
     *
     * Committing is not sending. The bytes go out coalesced with the first body chunk, which is what
     * lets the host answer a one-shot response in a single write with a computed `content-length`.
     * {@see self::flush()} forces them out early, at the cost of that. Interim `1xx` responses are not
     * writable here.
     *
     * @param int<200, 599> $status
     * @param array<non-empty-string, string|list<string>> $headers Sent as given, minus hop-by-hop
     *        headers and anything the protocol computes itself.
     * @throws HeadAlreadySentError The head has already committed.
     * @throws WorkDiscardedException The host closed the exchange first.
     * @throws \ValueError A header name or value is not representable on the wire.
     */
    public function writeHeader(int $status = 200, array $headers = []): void;

    /**
     * Write a body chunk, flushing it.
     *
     * @param bool $eos Ends the response and finalizes the exchange. Pass `false` to keep streaming;
     *        an empty chunk with `$eos` ends a response that has no more bytes to send. An empty chunk
     *        without it does nothing — a zero-length chunk is how a chunked body terminates, so there
     *        is no way to put one on the wire and keep the response open. To get a committed head out
     *        with no body yet, use {@see self::flush()}.
     * @throws AlreadyFinalizedError The response already ended.
     * @throws WorkDiscardedException The host closed the exchange first — the client is gone, the
     *         deadline passed, or the worker is draining. Streams learn about it here, on the next
     *         write, so a long one stops promptly.
     */
    public function writeBody(string $content, bool $eos = true): void;

    /**
     * End the response with a trailer section, finalizing the exchange. The other ending is
     * {@see self::writeBody()} carrying `$eos`.
     *
     * Put nothing here that the client needs. Intermediaries bridging protocol versions discard trailers
     * "in most cases" by RFC 9110's own account, which is why the same section says a server SHOULD NOT
     * send a trailer field it believes the user agent must receive. gRPC gets away with `grpc-status`
     * only by demanding end-to-end HTTP/2, and browsers never expose trailers to JavaScript at all.
     *
     * Delivered on HTTP/2 and later, as a final `HEADERS` frame. On HTTP/1.1 the chunked trailer section
     * exists on paper but the host does not write one, so they are dropped and the response still ends
     * normally — the same advisory treatment as {@see self::sendEarlyHints()}. An end-to-end HTTP/2
     * connection is the real precondition; `TE: trailers` is not one, it only promises that nothing
     * downstream will drop them. Should HTTP/1.1 delivery ever land, a `trailer` header in the head
     * declares the field names, which only the worker knows that early.
     *
     * @param array<non-empty-string, string|list<string>> $trailers
     * @throws AlreadyFinalizedError The response already ended.
     * @throws WorkDiscardedException The host closed the exchange first.
     * @throws \ValueError The field may not travel in a trailer section. RFC 9110 §6.5.1 puts this as an
     *         allowlist — the sender must know the field's own definition permits it — which no host can
     *         enforce, so what is enforced is the categories that rule protects: framing, routing,
     *         authentication, request modifiers, response controls and content format.
     */
    public function writeTrailers(array $trailers): void;

    /**
     * Put everything written so far on the wire.
     *
     * Needed only when the head must reach the client before any body exists — an event stream whose
     * first event is seconds away, a progress page, a response whose `content-type` alone lets the
     * client start work. Forcing the head out costs the host its `content-length`, so an HTTP/1.1
     * response falls back to chunked encoding unless the head carried one itself.
     *
     * @throws AlreadyFinalizedError The response already ended, so there is nothing pending and asking
     *         for a flush means the code lost track of the exchange.
     * @throws WorkDiscardedException The host closed the exchange first.
     */
    public function flush(): void;

    /**
     * Send one `103 Early Hints` interim response carrying exactly these headers.
     *
     * `send`, not `write`, and for two reasons: this is a message of its own rather than a piece of the
     * response the `write*` verbs build, and it reaches the wire by itself. A `103` sitting in a buffer
     * is pointless — its whole value is arriving while the server thinks — and there is no later event
     * of the same response to coalesce it with.
     *
     * Repeatable until the head commits. Advisory: the host emits it only where the protocol makes it
     * safe and drops it otherwise, so worker code never branches on `$request->protocol`.
     *
     * The other three assigned `1xx` codes need no verb, for three different reasons. `100` belongs to
     * whoever collects the body, and that is the host — it answers `Expect: 100-continue` before a worker
     * has the exchange, so PHP has nothing to say and no moment to say it in. `101` hands the socket to
     * whoever sent it, which an exchange with no read side cannot own — that is a plugin of its own, not a
     * status code. `102` is an idle-timeout ping on a timer, so it belongs to the host too; an application
     * that wants to say "still here" commits its head and writes bytes.
     *
     * @param array<non-empty-string, string|list<string>> $headers
     * @throws HeadAlreadySentError The head has already committed.
     * @throws WorkDiscardedException The host closed the exchange first.
     */
    public function sendEarlyHints(array $headers): void;
}
