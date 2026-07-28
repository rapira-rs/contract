<?php

declare(strict_types=1);

namespace Rapira\Plugin\Http;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Work;

/**
 * One HTTP request/response exchange: the request data plus the verbs that answer it.
 *
 * The response is written, never returned. The head commits once — explicitly via
 * {@see self::writeHead()}, or implicitly as `200` on the first body write — then body chunks follow
 * until the response ends, either on a chunk carrying `$eos` or on {@see self::writeTrailers()}. That
 * ending finalizes the exchange.
 *
 * ```php
 * $exchange->writeBody($html);                    // 200, one shot, one write on the wire
 *
 * $exchange->writeHead(200, ['content-type' => ['text/event-stream']]);
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
    public function writeHead(int $status = 200, array $headers = []): void;

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
     * Declare the field names in the head as a `trailer` header — only the worker knows them that early,
     * and HTTP/1.1 recipients that were not told may not look. On HTTP/2 and later there is nothing to
     * declare and the host sends a final `HEADERS` frame instead.
     *
     * Advisory, like {@see self::sendEarlyHints()}: RFC 9112 forbids a trailer section unless the request
     * carried `TE: trailers` or every field is metadata safe to discard, so the host drops them where it
     * must and the response still ends normally.
     *
     * @param array<non-empty-string, string|list<string>> $trailers
     * @throws AlreadyFinalizedError The response already ended.
     * @throws WorkDiscardedException The host closed the exchange first.
     * @throws \ValueError A field is one the trailer section may not carry — framing, routing,
     *         authentication, response control data or `content-*` (RFC 9110).
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
     * The other three assigned `1xx` codes need no verb, for three different reasons. `100` is already
     * answered by the time the host has collected the body; were request bodies ever streamed, the
     * answer would be the head written before the first read, as in Go, and still not a verb. `101`
     * hands the socket to whoever sent it, which an exchange with no read side cannot own — that is a
     * plugin of its own, not a status code. `102` is an idle-timeout ping on a timer, so it belongs to
     * the host; an application that wants to say "still here" commits its head and writes bytes.
     *
     * @param array<non-empty-string, string|list<string>> $headers
     * @throws HeadAlreadySentError The head has already committed.
     * @throws WorkDiscardedException The host closed the exchange first.
     */
    public function sendEarlyHints(array $headers): void;
}
