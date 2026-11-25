<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Support;

use RuntimeException;

/**
 * A request to the source of an extract could not be carried out: unexpected status, short read, a transfer that failed.
 *
 * Distinct from {@see SourceChangedException}, which is not an error but a signal that the pinned file moved.
 */
class HttpClientException extends RuntimeException
{
    public static function transport(string $url, string $message): self
    {
        return new self(sprintf('Transfer from %s failed: %s', $url, $message));
    }

    public static function unexpectedStatus(string $url, int $status): self
    {
        return new self(sprintf('Unexpected HTTP status %d for %s.', $status, $url));
    }

    public static function shortRead(string $url, int $expected, int $received): self
    {
        return new self(sprintf('Short read from %s: expected %d bytes, received %d.', $url, $expected, $received));
    }
}
