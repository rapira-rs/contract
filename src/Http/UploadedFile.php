<?php

declare(strict_types=1);

namespace Rapira\Http;

/**
 * A file part of a `multipart/form-data` body: any part whose `content-disposition` carries a
 * `filename` parameter. The host spools the bytes to disk as the upload streams in, so PHP never
 * holds them and the file's size is bounded by `rapira.toml`, not by `memory_limit`.
 *
 * The file lives until the exchange finalizes; `rename()` it to keep it.
 */
final readonly class UploadedFile
{
    /**
     * @param non-empty-string $name `name` from `content-disposition` — the form field this part
     *        answers, the fact the host routed by.
     * @param string $clientFilename `filename` byte-for-byte as sent — untrusted, never a path to
     *        touch. Its presence is what made this a file part; empty is a browser submitting an
     *        empty file input (PSR-7's `UPLOAD_ERR_NO_FILE`).
     * @param array<non-empty-string, list<string>> $headers The part's header section as received,
     *        the shape of {@see Request::$headers}. `content-type` lives here:
     *        `$headers['content-type'][0]` is PSR-7's `getClientMediaType()`.
     * @param non-empty-string $tmpPath Where the host spooled the bytes; `filesize()` is the size.
     *        Gone when the exchange finalizes — `rename()` to keep.
     */
    public function __construct(
        public string $name,
        public string $clientFilename,
        public array $headers,
        public string $tmpPath,
    ) {}
}
