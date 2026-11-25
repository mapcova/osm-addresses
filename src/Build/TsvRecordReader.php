<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Build;

use RuntimeException;

/**
 * A tab-separated file written by the OpenStreetMap extraction: a header line, then one record per line. Values have
 * their tabs and line breaks replaced by spaces when written, so a line is a record. The cursor is a byte offset,
 * always on the end of a record.
 */
final class TsvRecordReader implements RecordReader
{
    /** @var resource|null */
    private $handle = null;

    /** @var list<string|null>|null */
    private ?array $header = null;

    private bool $headerRead = false;

    private int $headerEnd = 0;

    private int $cursor = 0;

    public function __construct(private readonly string $path)
    {
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            fclose($this->handle);
        }
    }

    public function header(): ?array
    {
        if (!$this->headerRead) {
            $this->headerRead = true;
            $line = fgets($this->open());

            if ($line !== false) {
                $this->header = explode("\t", rtrim($line, "\r\n"));
                $this->headerEnd = $this->cursor = (int) ftell($this->open());
            }
        }

        return $this->header;
    }

    public function next(): ?array
    {
        $this->header();
        $line = fgets($this->open());

        if ($line === false) {
            return null;
        }

        $this->cursor = (int) ftell($this->open());

        return explode("\t", rtrim($line, "\r\n"));
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    public function seek(int $cursor): void
    {
        $this->header();
        $this->cursor = max($cursor, $this->headerEnd);
        fseek($this->open(), $this->cursor);
    }

    /** @return resource */
    private function open()
    {
        if ($this->handle === null) {
            $handle = @fopen($this->path, 'rb');

            if ($handle === false) {
                throw new RuntimeException('Cannot open ' . basename($this->path));
            }

            $this->handle = $handle;
        }

        return $this->handle;
    }
}
