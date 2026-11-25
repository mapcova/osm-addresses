<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Unit;

use MapCova\OsmAddresses\Build\TsvRecordReader;
use MapCova\OsmAddresses\Tests\TestCase;

/** The tab-separated records the extraction writes. */
final class TsvRecordReaderTest extends TestCase
{
    private function file(string $content): string
    {
        $path = $this->tempDir() . '/x.tsv';
        file_put_contents($path, $content);

        return $path;
    }

    public function testReadsTheHeaderThenOneRecordPerLineKeepingEmptyValues(): void
    {
        $reader = new TsvRecordReader($this->file("a\tb\tc\n1\t\t3\n\t\t\nx y\tz\t\n"));

        self::assertSame(['a', 'b', 'c'], $reader->header());
        self::assertSame(['1', '', '3'], $reader->next());
        self::assertSame(['', '', ''], $reader->next());
        self::assertSame(['x y', 'z', ''], $reader->next());
        self::assertNull($reader->next());
    }

    public function testTheCursorIsAByteOffsetOnTheEndOfARecord(): void
    {
        $content = "a\tb\n1\t2\n3\t4\n5\t6\n";
        $path = $this->file($content);
        $reader = new TsvRecordReader($path);
        $reader->header();

        self::assertSame(4, $reader->cursor());
        $reader->next();
        $reader->next();
        $cursor = $reader->cursor();
        self::assertSame(strlen("a\tb\n1\t2\n3\t4\n"), $cursor);

        $resumed = new TsvRecordReader($path);
        $resumed->seek($cursor);
        self::assertSame(['5', '6'], $resumed->next());
        self::assertNull($resumed->next());

        // A cursor inside the header lands after it.
        $again = new TsvRecordReader($path);
        $again->seek(0);
        self::assertSame(['1', '2'], $again->next());
    }

    public function testAnEmptyFileHasNoHeader(): void
    {
        $reader = new TsvRecordReader($this->file(''));

        self::assertNull($reader->header());
        self::assertNull($reader->next());
    }

    public function testAMissingFileIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        (new TsvRecordReader($this->tempDir() . '/none.tsv'))->header();
    }
}
