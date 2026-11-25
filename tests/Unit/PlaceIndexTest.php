<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Unit;

use MapCova\OsmAddresses\Osm\PlaceIndex;
use MapCova\OsmAddresses\Tests\TestCase;

/** The grid of place nodes: the nearest place within the radius of its type. */
final class PlaceIndexTest extends TestCase
{
    /** One degree of latitude is 111.32 km. */
    private const KM = 8_983;

    private function index(): PlaceIndex
    {
        $index = new PlaceIndex();
        $index->add('city', 'London', 51_500_000, -100_000, ['cy' => 'Llundain']);
        $index->add('town', 'Slough', 51_510_000, -590_000);
        $index->add('hamlet', 'Little Hamlet', 51_700_000, -300_000);
        // Not places of a kind that names an address, or no name.
        $index->add('suburb', 'Soho', 51_520_000, -130_000);
        $index->add('village', '', 51_800_000, 0);

        return $index;
    }

    public function testFindsTheNearestPlaceWithinItsRadius(): void
    {
        $index = $this->index();

        self::assertSame(3, $index->count());
        self::assertSame('London', $index->nearest(51_500_000 + 10 * self::KM, -100_000)['name'] ?? null);
        self::assertSame('Slough', $index->nearest(51_510_000, -590_000 + 20_000)['name'] ?? null);
        self::assertSame(['cy' => 'Llundain'], $index->nearest(51_500_000, -100_000)['names'] ?? null);
    }

    public function testEachTypeHasItsOwnRadius(): void
    {
        $index = $this->index();

        // 14 km north of the city (15 km radius): inside; 16 km: outside.
        self::assertSame('London', $index->nearest(51_500_000 + 14 * self::KM, -100_000)['name'] ?? null);
        self::assertNull($index->nearest(51_500_000 + 16 * self::KM, -100_000));
        // A hamlet names 1.5 km around it: 1 km yes, 2 km no.
        self::assertSame('Little Hamlet', $index->nearest(51_700_000 + 1 * self::KM, -300_000)['name'] ?? null);
        self::assertNull($index->nearest(51_700_000 + 2 * self::KM, -300_000));
        // A town names 8 km: 7 km yes, 9 km no.
        self::assertSame('Slough', $index->nearest(51_510_000, -590_000 - (int) (7 * self::KM / cos(deg2rad(51.51))))['name'] ?? null);
        self::assertNull($index->nearest(51_510_000, -590_000 - (int) (9 * self::KM / cos(deg2rad(51.51)))));
    }

    public function testTheNearestOfSeveralWins(): void
    {
        $index = new PlaceIndex();
        $index->add('city', 'Big', 52_000_000, 0);
        $index->add('hamlet', 'Small', 52_000_000 + 4 * self::KM, 0);

        self::assertSame('Small', $index->nearest(52_000_000 + 5 * self::KM, 0)['name'] ?? null);
        self::assertSame('Big', $index->nearest(52_000_000 + 2 * self::KM, 0)['name'] ?? null);
    }

    public function testWorksAcrossTheMeridianAndTheEquator(): void
    {
        $index = new PlaceIndex();
        $index->add('town', 'West', -100, -3_000_000);
        $index->add('town', 'East', 100, 3_000_000);

        self::assertSame('West', $index->nearest(0, -2_990_000)['name'] ?? null);
        self::assertSame('East', $index->nearest(-50, 2_990_000)['name'] ?? null);
        self::assertNull($index->nearest(0, 0));
    }

    public function testLoadsTheExtractedFile(): void
    {
        $path = $this->tempDir() . '/places.tsv';
        file_put_contents($path, "place\tname\tlat_e6\tlon_e6\tcy\tga\ncity\tLondon\t51500000\t-100000\tLlundain\t\ntown\tCork\t51898000\t-8470000\t\tCorcaigh\nsuburb\tSoho\t51520000\t-130000\t\t\n");
        $index = PlaceIndex::fromFile($path);

        self::assertSame(2, $index->count());
        self::assertSame(['ga' => 'Corcaigh'], $index->nearest(51_898_000, -8_470_000)['names'] ?? null);
        self::assertSame(['cy' => 'Llundain'], $index->nearest(51_500_000, -100_000)['names'] ?? null);
    }
}
