<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Unit;

use MapCova\OsmAddresses\Osm\IrelandBorder;
use MapCova\OsmAddresses\Tests\TestCase;
use RuntimeException;

final class IrelandBorderTest extends TestCase
{
    public function testAPolygonInsideAndOutsideAndOnTheEdgeCells(): void
    {
        // A triangle of 0.1 degree: cells inside it, cells it crosses, cells outside.
        $border = new IrelandBorder([[[54_000_000, -7_000_000], [54_100_000, -7_000_000], [54_000_000, -6_900_000]]]);

        self::assertTrue($border->contains(54_020_000, -6_990_000));
        self::assertTrue($border->contains(54_001_000, -6_999_000));
        self::assertFalse($border->contains(54_090_000, -6_910_000), 'beyond the hypotenuse, in a cell the edge crosses');
        self::assertFalse($border->contains(54_200_000, -6_950_000));
        self::assertFalse($border->contains(48_000_000, 2_000_000), 'out of the box');
    }

    public function testTheRealBorder(): void
    {
        $border = IrelandBorder::load();

        // Belfast, Armagh, Enniskillen, Rathlin and Derry are Northern Ireland's.
        foreach ([[54_597_300, -5_930_100], [54_350_000, -6_650_000], [54_343_900, -7_630_600], [55_300_000, -6_200_000], [54_996_600, -7_308_600]] as [$lat, $lon]) {
            self::assertTrue($border->contains($lat, $lon), $lat . ',' . $lon);
            self::assertFalse($border->inRepublic($lat, $lon), $lat . ',' . $lon);
        }

        // Dundalk, Monaghan, Clones, Letterkenny and Inishowen are the Republic's.
        foreach ([[54_000_000, -6_416_700], [54_249_200, -6_968_300], [54_180_000, -7_230_000], [54_950_000, -7_733_300], [55_200_000, -7_300_000]] as [$lat, $lon]) {
            self::assertFalse($border->contains($lat, $lon), $lat . ',' . $lon);
            self::assertTrue($border->inRepublic($lat, $lon), $lat . ',' . $lon);
        }

        // Kintyre and Galloway are Scotland's: neither.
        foreach ([[55_420_000, -5_600_000], [54_850_000, -4_900_000], [55_650_000, -6_200_000]] as [$lat, $lon]) {
            self::assertFalse($border->contains($lat, $lon), $lat . ',' . $lon);
            self::assertFalse($border->inRepublic($lat, $lon), $lat . ',' . $lon);
        }
    }

    public function testAMissingFileIsAnError(): void
    {
        $this->expectException(RuntimeException::class);
        IrelandBorder::load($this->tempDir() . '/none.json');
    }
}
