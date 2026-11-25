<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Unit;

use MapCova\OsmAddresses\Pbf\Block;
use MapCova\OsmAddresses\Pbf\PbfFormatException;
use MapCova\OsmAddresses\Pbf\Wire;
use PHPUnit\Framework\TestCase;

/** A damaged or crafted file must fail at once, never loop or read past its buffer. */
final class PbfWireTest extends TestCase
{
    public function testAVarintIsReadAndAdvances(): void
    {
        $p = 0;
        self::assertSame(300, Wire::varint("\xAC\x02", $p));
        self::assertSame(2, $p);
    }

    public function testAVarintPastTheEndIsRefused(): void
    {
        $p = 0;
        $this->expectException(PbfFormatException::class);
        Wire::varint("\x80\x80", $p);
    }

    public function testAVarintLongerThanTenBytesIsRefused(): void
    {
        $p = 0;
        $this->expectException(PbfFormatException::class);
        Wire::varint(str_repeat("\xFF", 11) . "\x01", $p);
    }

    public function testANegativeLengthIsRefused(): void
    {
        // Ten bytes setting bit 63: a negative int, which would send a parser backwards.
        $p = 0;
        $this->expectException(PbfFormatException::class);
        Wire::length(str_repeat("\xFF", 9) . "\x01" . 'abc', $p);
    }

    public function testALengthPastTheEndIsRefused(): void
    {
        $p = 0;
        $this->expectException(PbfFormatException::class);
        Wire::length("\x05ab", $p);
    }

    public function testALengthThatFitsIsReturned(): void
    {
        $p = 0;
        self::assertSame(2, Wire::length("\x02ab", $p));
        self::assertSame(1, $p);
    }

    public function testAPrimitiveBlockWithANegativeFieldLengthFailsInsteadOfLooping(): void
    {
        // Field 2 (primitive group), wire type 2, then a negative length.
        $this->expectException(PbfFormatException::class);
        Block::decode("\x12" . str_repeat("\xFF", 9) . "\x01" . str_repeat("\x00", 16));
    }

    public function testAPrimitiveBlockWithAStringPastItsTableFails(): void
    {
        // String table (field 1) of 3 bytes holding a string of 50.
        $this->expectException(PbfFormatException::class);
        Block::decode("\x0A\x03\x0A\x32x");
    }
}
