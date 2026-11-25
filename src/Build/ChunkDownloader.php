<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Build;

use MapCova\OsmAddresses\Support\ByteRange;
use MapCova\OsmAddresses\Support\HttpClientException;
use MapCova\OsmAddresses\Support\SafeHttpClient;
use MapCova\OsmAddresses\Support\SourceChangedException;

/**
 * Downloads a chunk by ranges of a few megabytes, so that a tick can stop
 * after any of them and the next one carry on from the bytes already on disk.
 * `If-Range` pins the file: when the source publishes a new one in between,
 * the caller gets a {@see SourceChangedException} and starts the chunk over.
 */
final class ChunkDownloader
{
    public const RANGE_BYTES = 33_554_432;

    public function __construct(
        private readonly SafeHttpClient $http,
        private readonly int $rangeBytes = self::RANGE_BYTES,
    ) {
    }

    /** The most a server that answers no byte range is asked for in one request. */
    public const WHOLE_FILE_MAX_BYTES = 104_857_600;

    /**
     * Size and version of the file, from a `HEAD` (or, when the server refuses it, a one-byte range).
     *
     * @return array{size: int, etag: ?string, last_modified: ?string, sha256: ?string, url: string}
     *
     * @throws PermanentBuildException when the source does not have the file any more
     * @throws HttpClientException     on a transport failure
     */
    public function probe(string $url): array
    {
        $head = self::head($this->http, $url);

        if ($head['status'] === 404 || $head['status'] === 410) {
            throw new PermanentBuildException('err_source_gone', [$head['status']]);
        }

        if ($head['status'] < 200 || $head['status'] >= 300 || $head['size'] === null) {
            throw HttpClientException::unexpectedStatus($url, $head['status']);
        }

        $sha = $head['headers']['x-amz-meta-sha256'] ?? null;

        return [
            'size' => $head['size'],
            'etag' => $head['etag'],
            'last_modified' => $head['last_modified'],
            'sha256' => is_string($sha) && preg_match('/^[0-9a-f]{64}$/', $sha) === 1 ? $sha : null,
            // Where the redirects ended: every range is fetched from the very file whose validators were read.
            'url' => $head['url'],
        ];
    }

    /**
     * A `HEAD`; when the server refuses it (some servers answer 401 to a `HEAD` and serve the file
     * to a `GET`), a `GET` of the first byte, whose `Content-Range` gives the size. The answer has the
     * shape of {@see SafeHttpClient::head()}; a server that has no such range is a `HEAD` failure still.
     *
     * @return array{status: int, headers: array<string,string>, size: ?int, etag: ?string, last_modified: ?string, url: string}
     *
     * @throws HttpClientException on a transport failure
     */
    public static function head(SafeHttpClient $http, string $url): array
    {
        $head = $http->head($url);

        if (!in_array($head['status'], [401, 403, 405, 501], true)) {
            return $head;
        }

        try {
            // The sink refuses the body: only the headers are wanted (some servers answer a whole file to a range).
            $response = $http->get($url, new ByteRange(0, 1), null, static fn (string $data): bool => false);
        } catch (SourceChangedException) {
            return $head;
        }

        $range = preg_match('#^bytes 0-\d+/(\d+)$#i', trim($response['headers']['content-range'] ?? ''), $match) === 1 ? (int) $match[1] : null;

        if ($response['status'] !== 206 || $range === null) {
            return $head;
        }

        return [
            'status' => 200,
            'headers' => $response['headers'],
            'size' => $range,
            'etag' => $response['headers']['etag'] ?? null,
            'last_modified' => $response['headers']['last-modified'] ?? null,
            'url' => $response['url'],
        ];
    }

    /** Whether `$etag` can pin a file across ranges (a weak or missing one cannot). */
    public static function isStrong(?string $etag): bool
    {
        return $etag !== null && $etag !== '' && !str_starts_with($etag, 'W/');
    }

    /**
     * The whole file in one request, for a server that answers no byte range and a file small enough
     * to come within a tick (the answer must be `200` with exactly `$size` bytes).
     *
     * @return int bytes now on disk
     *
     * @throws PermanentBuildException when the file is too large for one request, or gone
     * @throws HttpClientException     on a transport failure or a short read
     */
    public function fetchWhole(string $url, string $path, int $size): int
    {
        if ($size > self::WHOLE_FILE_MAX_BYTES) {
            throw new PermanentBuildException('err_source_no_range', [(int) ceil($size / 1_000_000)]);
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new PermanentBuildException('err_build_dir_unwritable');
        }

        try {
            $received = 0;
            $response = $this->http->get($url, null, null, static function (string $data) use ($handle, &$received, $size): bool {
                if ($received + strlen($data) > $size) {
                    return false;
                }

                $received += strlen($data);

                return fwrite($handle, $data) === strlen($data);
            });

            if ($response['status'] === 404 || $response['status'] === 410) {
                throw new PermanentBuildException('err_source_gone', [$response['status']]);
            }

            if ($response['status'] !== 200) {
                throw HttpClientException::unexpectedStatus($url, $response['status']);
            }

            if ($received !== $size) {
                throw HttpClientException::shortRead($url, $size, $received);
            }

            fflush($handle);
        } finally {
            fclose($handle);
        }

        return $size;
    }

    /** The most a page of a service may weigh; a page is a few megabytes. */
    public const PAGE_MAX_BYTES = 134_217_728;

    /**
     * One page of a service, whole, into `$path` (written aside and renamed: a page is there complete or not at all).
     *
     * @throws PermanentBuildException when the service does not have the page any more, or the page is too large
     * @throws HttpClientException     on a transport failure or a refused request
     */
    public function fetchPage(string $url, string $path): void
    {
        $handle = fopen($path . '.part', 'wb');

        if ($handle === false) {
            throw new PermanentBuildException('err_build_dir_unwritable');
        }

        try {
            $received = 0;
            $response = $this->http->get($url, null, null, static function (string $data) use ($handle, &$received): bool {
                $received += strlen($data);

                return $received <= self::PAGE_MAX_BYTES && fwrite($handle, $data) === strlen($data);
            });

            if ($response['status'] === 404 || $response['status'] === 410) {
                throw new PermanentBuildException('err_source_gone', [$response['status']]);
            }

            if ($response['status'] !== 200) {
                throw HttpClientException::unexpectedStatus($url, $response['status']);
            }

            if ($received > self::PAGE_MAX_BYTES) {
                throw new PermanentBuildException('err_source_size', ['page', (int) ceil($received / 1_000_000)]);
            }

            fflush($handle);
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($path . '.part');

            throw $e;
        }

        fclose($handle);

        if (!rename($path . '.part', $path)) {
            throw new TransientBuildException('err_put_in_place');
        }
    }

    /**
     * Fetch the next range of the file at `$url` into `$path`, from `$downloaded`.
     *
     * @return int bytes now on disk
     *
     * @param string $url the effective URL of the probe
     *
     * @throws SourceChangedException when the file changed since `$etag` / `$lastModified` was read
     * @throws HttpClientException    on a transport failure or a short read
     * @throws PermanentBuildException when the file is gone
     */
    public function fetch(string $url, string $path, int $downloaded, int $size, ?string $etag, ?string $lastModified): int
    {
        $onDisk = is_file($path) ? (int) filesize($path) : 0;
        $downloaded = min($downloaded, $onDisk);
        $length = min($this->rangeBytes, $size - $downloaded);

        if ($length <= 0) {
            return $downloaded;
        }

        $handle = fopen($path, $onDisk > 0 ? 'r+b' : 'wb');

        if ($handle === false) {
            throw new PermanentBuildException('err_build_dir_unwritable');
        }

        try {
            ftruncate($handle, max(0, $downloaded));
            fseek($handle, $downloaded);
            $received = 0;
            // A weak validator is no use to If-Range.
            $ifRange = $etag !== null && !str_starts_with($etag, 'W/') ? $etag : $lastModified;

            $response = $this->http->get(
                $url,
                new ByteRange($downloaded, $length),
                $ifRange,
                static function (string $data) use ($handle, &$received, $length): bool {
                    if ($received + strlen($data) > $length) {
                        return false;
                    }

                    $received += strlen($data);

                    return fwrite($handle, $data) === strlen($data);
                },
            );

            if ($response['status'] === 404 || $response['status'] === 410) {
                throw new PermanentBuildException('err_source_gone', [$response['status']]);
            }

            if ($response['status'] !== 206) {
                throw HttpClientException::unexpectedStatus($url, $response['status']);
            }

            // The server must have answered the range that was asked, of the file that was probed.
            $expected = sprintf('bytes %d-%d/%d', $downloaded, $downloaded + $length - 1, $size);

            if (strtolower(trim($response['headers']['content-range'] ?? '')) !== $expected) {
                throw HttpClientException::transport($url, 'the answer does not match the requested range');
            }

            if ($received !== $length) {
                throw HttpClientException::shortRead($url, $length, $received);
            }

            fflush($handle);

            if (function_exists('fsync')) {
                fsync($handle);
            }
        } finally {
            fclose($handle);
        }

        return $downloaded + $length;
    }
}
