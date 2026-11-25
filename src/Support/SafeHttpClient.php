<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Support;

/**
 * What the builder needs to read the extract of a source: its validators (size, ETag, Last-Modified) and byte ranges.
 * The command line gives it a client that reads a local file ({@see FileHttpClient}); the tests give it fixtures.
 */
interface SafeHttpClient
{
    /**
     * The returned `url` is the effective one after redirects; it is what a
     * later ranged request must target so that every byte comes from the very
     * archive whose identity was captured here.
     *
     * @return array{status: int, headers: array<string,string>, size: ?int, etag: ?string, last_modified: ?string, url: string}
     */
    public function head(string $url): array;

    /**
     * Fetch a URL, optionally a byte range.
     *
     * A sink must never throw. To
     * refuse the rest of a body it returns `false`: the transfer is aborted
     * immediately, `get()` returns what was read so far, and the sink's owner
     * raises its own error afterwards — it is the one that knows why it
     * stopped, and which limit it was holding.
     *
     * @param (callable(string): bool)|null $sink receives the body in chunks and
     *                                            returns `false` to abort; when
     *                                            null the body is returned in `body`
     *
     * @return array{status: int, headers: array<string,string>, body: string, url: string}
     *
     * @throws HttpClientException    on a transport failure
     * @throws SourceChangedException when a ranged request is answered 200 or 412
     */
    public function get(string $url, ?ByteRange $range = null, ?string $ifRange = null, ?callable $sink = null): array;

    /**
     * Fetch several ranges concurrently.
     *
     * @param list<ByteRange>                    $ranges
     * @param callable(int, string, int): void   $onChunk receives (range index, bytes, offset within the range)
     *
     * @throws SourceChangedException when the pinned build changed
     */
    public function multiRanges(string $url, array $ranges, callable $onChunk, int $parallel = 6, ?string $ifRange = null): void;
}
