<?php

declare(strict_types=1);

namespace Rapira\Http;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Work;

/**
 * One HTTP request/response exchange: the request data plus the verbs that answer it.
 *
 * The response is written, never returned. Interim `1xx` heads go out at once and may repeat; the final
 * head commits once — explicitly via {@see self::writeHead()}, or implicitly as `200` on the first body
 * write — then body chunks follow until the response ends, either on a chunk carrying `$eos` or on
 * {@see self::writeTrailers()}. That ending finalizes the exchange.
 *
 * ```php
 * $exchange->writeBody($html);                    // 200, one shot, one write on the wire
 *
 * $exchange->writeHead(103, ['link' => ['</app.css>; rel=preload']]);
 * $exchange->writeHead(200, ['content-type' => ['text/html']]);
 * $exchange->writeBody($html);                    // the hint was already on the wire
 *
 * $exchange->writeHead(200, ['content-type' => ['text/event-stream']]);
 * $exchange->flush();                             // the client sees the head before event one
 * foreach ($events as $event) {
 *     $exchange->writeBody($event, eos: false);   // each body write reaches the wire
 * }
 * $exchange->writeBody('', eos: true);
 * ```
 */
interface Exchange extends Work
{
    public function getRequest(): Request;

    /**
     * Write a response head: a status and the fields that belong with it.
     *
     * A status of `100`–`199` is an interim response. It reaches the wire at once, on its own, and may be
     * repeated — `103 Early Hints` is what that is for. Interim responses are advisory: the host emits them
     * only where the protocol allows, an HTTP/1.0 client being one place it does not, and drops them
     * otherwise, so worker code never branches on {@see Request::$protocol}.
     *
     * `101` is not interim. It ends the HTTP conversation, so it counts as a final head, and nothing here
     * hands over the connection it promises.
     *
     * A final head — `101`, or `200`–`599` — commits once and closes the door on interim responses.
     * Committing is not sending: the bytes go out coalesced with the first body chunk, so a one-shot
     * response is a single write with a computed `content-length`, and {@see self::flush()} forces them out
     * early at the cost of that. Writing one is optional, since the first {@see self::writeBody()} commits
     * `200` with no fields.
     *
     * @param int<100, 599> $status Any code the protocol allows, registered or not — `499` and `520` are
     *        as writable as `404`.
     * @param array<non-empty-string, list<string>> $headers One entry per value, the shape
     *        {@see Request::$headers} arrives in. Sent as given, minus hop-by-hop headers and anything the
     *        protocol computes itself.
     * @throws HeadAlreadySentError The final head has already been written.
     * @throws WorkDiscardedException The host closed the exchange first.
     * @throws \ValueError The status is outside `100`–`599`, or a header name or value is not
     *         representable on the wire.
     */
    public function writeHead(int $status, array $headers = []): void;

    /**
     * Write a body chunk, flushing it.
     *
     * @param bool $eos Ends the response and finalizes the exchange. Pass `false` to keep streaming; an
     *        empty chunk with `$eos` ends a response that has no more bytes to send. An empty chunk
     *        without it does nothing, since a zero-length chunk is how a chunked body terminates — to
     *        get a committed head out with no body yet, use {@see self::flush()}.
     * @throws AlreadyFinalizedError The response already ended.
     * @throws WorkDiscardedException The host closed the exchange first — the client is gone, the
     *         deadline passed, or the worker is draining. A stream learns of it here, on its next write.
     */
    public function writeBody(string $content, bool $eos = true): void;

    /**
     * End the response with a trailer section, finalizing the exchange. The other ending is
     * {@see self::writeBody()} carrying `$eos`.
     *
     * Delivered over end-to-end HTTP/2 and later, as a final `HEADERS` frame. On HTTP/1.1 they are
     * dropped and the response still ends normally, so put nothing here that the client needs — the same
     * advisory treatment an interim head gets.
     *
     * @param array<non-empty-string, list<string>> $trailers
     * @throws AlreadyFinalizedError The response already ended.
     * @throws WorkDiscardedException The host closed the exchange first.
     * @throws \ValueError The field may not travel in a trailer section: framing, routing,
     *         authentication, request modifiers, response controls and content format all stay in the
     *         head (RFC 9110 §6.5.1).
     */
    public function writeTrailers(array $trailers): void;

    /**
     * Put everything written so far on the wire.
     *
     * For a head that has to reach the client before any body exists — an event stream whose first event
     * is seconds away, a response whose `content-type` alone lets the client start work. Costs the host
     * its `content-length`, so an HTTP/1.1 response falls back to chunked encoding unless the head
     * carried one itself.
     *
     * @throws AlreadyFinalizedError The response already ended.
     * @throws WorkDiscardedException The host closed the exchange first.
     */
    public function flush(): void;
}
