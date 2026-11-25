<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Sources;

use MapCova\OsmAddresses\Build\RecordReader;

/**
 * A source that opens its chunks itself (the TSV the extraction writes).
 */
interface ReaderSource extends Source
{
    /** The records of a downloaded chunk. */
    public function reader(string $path, Chunk $chunk): RecordReader;
}
