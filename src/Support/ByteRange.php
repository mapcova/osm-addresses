<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Support;

/**
 * A half-open byte range `[offset, offset + length)`.
 */
final readonly class ByteRange
{
    public function __construct(
        public int $offset,
        public int $length,
    ) {
    }

    public function end(): int
    {
        return $this->offset + $this->length - 1;
    }

    /** Value for an HTTP `Range` header. */
    public function headerValue(): string
    {
        return sprintf('bytes=%d-%d', $this->offset, $this->end());
    }
}
