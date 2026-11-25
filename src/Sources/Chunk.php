<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Sources;

/**
 * One file to read: the OpenStreetMap extract of a country.
 */
final class Chunk
{
    public const PLAIN = 'plain';

    /**
     * @param string       $key    stable name of the chunk inside its source (`gb`, `ie`)
     * @param self::PLAIN  $format
     * @param bool         $ranged whether the server answers byte ranges (one that does not is downloaded in a single request)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $url,
        public readonly string $format,
        public readonly bool $ranged = true,
    ) {
    }
}
