<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Pbf;

use RuntimeException;

/** A file that is no usable OSM PBF; `$key` names the message of `messages.en.json` the builder shows for it. */
final class PbfFormatException extends RuntimeException
{
    public function __construct(public readonly string $key, string $detail)
    {
        parent::__construct($detail);
    }
}
