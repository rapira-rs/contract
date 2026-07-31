<?php

declare(strict_types=1);

namespace Rapira\Http;

/**
 * A `multipart/form-data` body the host parsed as the upload streamed in. Arrives on
 * {@see Request::$body} in place of the raw bytes, so the payload has one spelling and the engine
 * enforces it: a request is never both raw and parsed.
 */
final readonly class Multipart
{
    /**
     * @param list<FormField> $fields Field parts — no `filename` in `content-disposition` — in
     *        document order, buffered in memory.
     * @param list<UploadedFile> $files File parts, spooled to disk, in document order.
     */
    public function __construct(
        public array $fields,
        public array $files,
    ) {}
}
