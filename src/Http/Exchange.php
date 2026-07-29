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
 */
interface Exchange extends Work
{
    public function getRequest(): Request;

    /**
     * Commit the response head: fix the status and headers, and close the window for
     * {@see self::sendEarlyHints()}. Optional — the first {@see self::writeBody()} commits `200` with no
     * headers.
     *
     * Committing is not sending. The bytes go out coalesced with the first body chunk, so a one-shot
     * response is a single write with a computed `content-length`; {@see self::flush()} forces them out
     * early at the cost of that. Interim `1xx` responses are not writable here.
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
     * advisory treatment as {@see self::sendEarlyHints()}.
     *
     * @param array<non-empty-string, string|list<string>> $trailers
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

    /**
     * Send one `103 Early Hints` interim response carrying exactly these headers, immediately.
     *
     * Repeatable until the head commits. Advisory: the host emits it only where the protocol makes it
     * safe and drops it otherwise, so worker code never branches on {@see Request::$protocol}.
     *
     * @param array<non-empty-string, string|list<string>> $headers
     * @throws HeadAlreadySentError The head has already committed.
     * @throws WorkDiscardedException The host closed the exchange first.
     */
    public function sendEarlyHints(array $headers): void;
}
