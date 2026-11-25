<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Support;

use LogicException;

/**
 * Serves one local OpenStreetMap extract to the builder as if it were the Geofabrik URL it was made for: the same
 * validators (a size, a strong ETag), byte ranges, no redirect and no `.md5`. No network is used.
 */
final class FileHttpClient implements SafeHttpClient
{
    private readonly int $size;

    private readonly string $etag;

    public function __construct(private readonly string $file, private readonly string $url)
    {
        $this->size = (int) filesize($file);
        $this->etag = '"' . md5($this->size . '-' . (int) filemtime($file) . '-' . basename($file)) . '"';
    }

    public function head(string $url): array
    {
        return [
            'status' => $url === $this->url ? 200 : 404,
            'headers' => ['etag' => $this->etag, 'content-length' => (string) $this->size],
            'size' => $this->size,
            'etag' => $this->etag,
            'last_modified' => gmdate('D, d M Y H:i:s', (int) filemtime($this->file)) . ' GMT',
            'url' => $this->url,
        ];
    }

    public function get(string $url, ?ByteRange $range = null, ?string $ifRange = null, ?callable $sink = null): array
    {
        if ($url !== $this->url) {
            return ['status' => 404, 'headers' => [], 'body' => '', 'url' => $url];
        }

        $handle = fopen($this->file, 'rb');

        if ($handle === false) {
            throw new HttpClientException('cannot open ' . $this->file);
        }

        fseek($handle, $range->offset ?? 0);
        $left = $range->length ?? $this->size;
        $read = 0;
        $body = '';

        // Streamed in pieces of 1 MiB, stopping when the sink says so.
        while ($left > 0 && !feof($handle)) {
            $piece = (string) fread($handle, min($left, 1_048_576));

            if ($piece === '') {
                break;
            }

            $left -= strlen($piece);
            $read += strlen($piece);

            if ($sink === null) {
                $body .= $piece;
            } elseif ($sink($piece) === false) {
                break;
            }
        }

        fclose($handle);
        $headers = ['etag' => $this->etag];

        if ($range !== null) {
            $headers['content-range'] = sprintf('bytes %d-%d/%d', $range->offset, $range->offset + $read - 1, $this->size);
        }

        return ['status' => $range === null ? 200 : 206, 'headers' => $headers, 'body' => $body, 'url' => $this->url];
    }

    public function multiRanges(string $url, array $ranges, callable $onChunk, int $parallel = 6, ?string $ifRange = null): void
    {
        throw new LogicException('Not used.');
    }
}
