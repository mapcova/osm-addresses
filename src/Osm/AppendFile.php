<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Osm;

use MapCova\OsmAddresses\Build\PermanentBuildException;

/**
 * An output of the extraction that only grows. Opened at the length the checkpoint recorded: what a killed unit wrote
 * after it is cut off, so redoing the unit never doubles it. Writes are buffered; {@see self::flush()} puts them on disk,
 * and the length is what the checkpoint records.
 */
final class AppendFile
{
    private const BUFFER = 65_536;

    /** @var resource */
    private $handle;

    private string $buffer = '';

    private int $length;

    /**
     * @param int $length bytes the file must hold; fewer on disk is a lost file
     *
     * @throws PermanentBuildException when the file is missing or shorter than recorded
     */
    public function __construct(string $path, int $length)
    {
        $handle = @fopen($path, $length === 0 ? 'c+b' : 'r+b');

        if ($handle === false || (int) (fstat($handle)['size'] ?? 0) < $length) {
            throw new PermanentBuildException('err_osm_state');
        }

        ftruncate($handle, max(0, $length));
        fseek($handle, $length);
        $this->handle = $handle;
        $this->length = $length;
    }

    public function __destruct()
    {
        $this->flush();
        fclose($this->handle);
    }

    public function write(string $bytes): void
    {
        $this->buffer .= $bytes;
        $this->length += strlen($bytes);

        if (strlen($this->buffer) >= self::BUFFER) {
            $this->flush();
        }
    }

    /** Bytes written so far, buffered ones included. */
    public function length(): int
    {
        return $this->length;
    }

    public function flush(): void
    {
        if ($this->buffer !== '') {
            fwrite($this->handle, $this->buffer);
            $this->buffer = '';
        }

        fflush($this->handle);
    }
}
