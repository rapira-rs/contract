<?php

declare(strict_types=1);

namespace Rapira\Http;

/**
 * A field part of a `multipart/form-data` body: any part whose `content-disposition` carries no
 * `filename`. Buffered in memory — the host's limits keep fields small.
 *
 * The header section is kept because a part is more than its value: an API client marks a field
 * `content-type: application/json`, and the `_charset_` mechanism rides on fields too. A browser's
 * plain text field carries nothing here but its `content-disposition`.
 */
final readonly class FormField
{
    /**
     * @param non-empty-string $name `name` from `content-disposition`.
     * @param string $value The part's body, bytes as received: no charset conversion, no decoding.
     * @param array<non-empty-string, list<string>> $headers The part's header section as received,
     *        the shape of {@see Request::$headers}.
     */
    public function __construct(
        public string $name,
        public string $value,
        public array $headers,
    ) {}
}
