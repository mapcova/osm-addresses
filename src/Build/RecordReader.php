<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Build;

/**
 * Reads the records of a downloaded chunk one at a time, and can resume from
 * a cursor it handed out earlier. The cursor is the reader's own business
 * (decompressed bytes for a CSV): the builder only stores it and gives it back.
 */
interface RecordReader
{
    /**
     * The column names of the chunk, `null` for an empty chunk.
     *
     * @return list<string|null>|null
     */
    public function header(): ?array;

    /**
     * The next record, `null` at the end.
     *
     * @return list<string|null>|null
     */
    public function next(): ?array;

    /** Where the reader stands: just after the last record it returned (after the header before any). */
    public function cursor(): int;

    /** Carry on from a cursor taken earlier; a cursor inside the header lands after it. */
    public function seek(int $cursor): void;
}
