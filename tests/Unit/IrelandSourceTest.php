<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Unit;

use MapCova\OsmAddresses\Sources\AddressRow;
use MapCova\OsmAddresses\Sources\IrelandSource;
use MapCova\OsmAddresses\Tests\Support\OsmFixtures;
use MapCova\OsmAddresses\Tests\TestCase;

final class IrelandSourceTest extends TestCase
{
    /** @param array<string,string> $values */
    private function row(array $values, ?IrelandSource $source = null): ?AddressRow
    {
        // Kinsale, in the Republic.
        return OsmFixtures::row($source ?? new IrelandSource(), $values + ['lat_e6' => '51706000', 'lon_e6' => '-8522000', 'city' => 'Kinsale']);
    }

    public function testTheSourceDescribesItself(): void
    {
        $source = new IrelandSource();

        self::assertSame(['IE', 'osm', 'ODbL 1.0'], [$source->country(), $source->id(), $source->licence()]);
        self::assertSame('© OpenStreetMap contributors, ODbL 1.0 — https://www.openstreetmap.org/copyright', $source->attribution());
        self::assertFalse($source->hasTrigram());
        self::assertSame(['ga', 'en'], $source->languages());
        self::assertStringContainsString('ireland-and-northern-ireland-latest.osm.pbf', $source->versionUrl());
    }

    public function testNumbersAndNamedHouses(): void
    {
        self::assertSame(['5', 'a'], [$this->row(['number' => '5a'])?->number, $this->row(['number' => '5a'])?->suffix]);
        self::assertSame(['12', '/14'], [$this->row(['number' => '12/14'])?->number, $this->row(['number' => '12/14'])?->suffix]);
        self::assertSame('Rose Cottage, Main Street', $this->row(['housename' => 'Rose Cottage', 'street' => 'Main Street'])?->name);
        self::assertSame('Ballymore', $this->row(['number' => '3', 'street' => '', 'place' => 'Ballymore'])?->name);
    }

    public function testEircodesAreSplitInRoutingKeyAndUniqueIdentifier(): void
    {
        foreach (['T12 X285' => ['T12', 'X285'], 't12x285' => ['T12', 'X285'], 'D02 X285' => ['D02', 'X285'], 'D6W 1234' => ['D6W', '1234'], 'A65 F4E2' => ['A65', 'F4E2']] as $text => [$key, $unique]) {
            $row = $this->row(['number' => '1', 'postcode' => (string) $text]);
            self::assertSame([$key, $unique], [$row?->postcode, $row?->pc], (string) $text);
        }

        // A routing key alone is the street's.
        self::assertSame(['T23', null], [$this->row(['number' => '1', 'postcode' => 'T23'])?->postcode, $this->row(['number' => '1', 'postcode' => 'T23'])?->pc]);
    }

    public function testDublinPostalDistrictsAreRoutingKeys(): void
    {
        foreach (['Dublin 2' => 'D02', 'dublin 12' => 'D12', 'Dublin 6W' => 'D6W', 'Dublin 6w' => 'D6W', 'Dublin 24' => 'D24'] as $text => $key) {
            $row = $this->row(['number' => '1', 'postcode' => (string) $text]);
            self::assertSame([$key, null], [$row?->postcode, $row?->pc], (string) $text);
        }

        foreach (['Dublin 25', 'Dublin 0', 'Dublin', 'Cork 3'] as $text) {
            self::assertNull($this->row(['number' => '1', 'postcode' => $text])?->postcode, $text);
        }
    }

    public function testAnInvalidEircodeIsNoPostcodeAndTheAddressStays(): void
    {
        $source = new IrelandSource();

        foreach (['XYZ 9', 'B12 1234', 'T12 X28', 'T12 X28I', 'SW1A 1AA', '12345'] as $text) {
            $row = $this->row(['number' => '1', 'postcode' => $text], $source);
            self::assertNotNull($row, $text);
            self::assertSame([null, null], [$row->postcode, $row->pc], $text);
        }

        self::assertSame(6, $source->tallies()['bad_postcode']);
    }

    public function testTheCityComesFromTheTagThenTheNearestPlaceAndNotFromAPostcode(): void
    {
        $dir = $this->tempDir();
        $source = new IrelandSource();
        OsmFixtures::load($source, $dir, [['town', 'Kinsale', 51_705_000, -8_523_000, '', ''], ['hamlet', 'Compass Hill', 51_800_000, -8_523_000, '', '']], [], ['T12' => 'Elsewhere']);

        self::assertSame('Cork', $this->row(['number' => '1', 'city' => 'Cork'], $source)?->city);
        self::assertSame('Kinsale', $this->row(['number' => '1', 'city' => '', 'postcode' => 'T12 X285'], $source)?->city);
        self::assertNull($this->row(['number' => '1', 'city' => '', 'lat_e6' => '53000000', 'lon_e6' => '-8000000'], $source));
    }

    public function testTheBorderIsKeptOnBothSides(): void
    {
        // Dundalk, Letterkenny, Lifford, Clones and Monaghan are the Republic's; Newry, Strabane, Belfast and Enniskillen are not.
        foreach ([[54_000_000, -6_416_700], [54_950_000, -7_733_300], [54_832_900, -7_482_400], [54_180_000, -7_230_000], [54_249_200, -6_968_300], [53_349_800, -6_260_300]] as [$lat, $lon]) {
            self::assertNotNull($this->row(['number' => '1', 'lat_e6' => (string) $lat, 'lon_e6' => (string) $lon]), $lat . ',' . $lon);
        }

        foreach ([[54_175_100, -6_340_200], [54_827_500, -7_463_600], [54_597_300, -5_930_100], [54_343_900, -7_630_600]] as [$lat, $lon]) {
            self::assertNull($this->row(['number' => '1', 'lat_e6' => (string) $lat, 'lon_e6' => (string) $lon]), $lat . ',' . $lon);
        }

        // Great Britain is out of the box.
        self::assertNull($this->row(['number' => '1', 'lat_e6' => '51482000', 'lon_e6' => '-3175000']));
    }

    public function testIrishAndEnglishFormsGoToNamesAndToTheSearchText(): void
    {
        $dir = $this->tempDir();
        $source = new IrelandSource();
        // Irish `name`, English `name:en`: the form of the language the address is not in.
        OsmFixtures::load(
            $source,
            $dir,
            [['town', 'An Daingean', 52_140_000, -10_270_000, '', 'Dingle'], ['city', 'Cork', 51_898_500, -8_475_600, 'Corcaigh', '']],
            [['Sráid na Trá', 52_140_500, -10_268_800, '', 'Beach Street'], ['Main Street', 51_706_000, -8_522_000, 'An tSráid Mhór', '']],
        );

        $irish = $this->row(['number' => '8', 'street' => 'Sráid na Trá', 'city' => 'An Daingean', 'lat_e6' => '52140900', 'lon_e6' => '-10268600'], $source);
        self::assertSame('{"en":["Beach Street","Dingle"]}', $irish?->names);
        self::assertSame('Beach Street Dingle', $irish->extra);

        $english = $this->row(['number' => '3', 'street' => 'Main Street', 'city' => 'Cork', 'lat_e6' => '51706100', 'lon_e6' => '-8522100'], $source);
        self::assertSame('{"ga":["An tSráid Mhór","Corcaigh"]}', $english?->names);
        self::assertSame('An tSráid Mhór Corcaigh', $english->extra);

        // The form is found by the other language's name too (the address says "Beach Street").
        $byEnglish = $this->row(['number' => '8', 'street' => 'Beach Street', 'city' => 'Dingle', 'lat_e6' => '52140900', 'lon_e6' => '-10268600'], $source);
        self::assertSame('{"ga":["Sráid na Trá","An Daingean"]}', $byEnglish?->names);
    }

    public function testTheApostropheVariantOfIrishNames(): void
    {
        self::assertSame('OConnell Street', $this->row(['number' => '5', 'street' => "O'Connell Street"])?->extra);
        self::assertSame("O'Connell Street", $this->row(['number' => '5', 'street' => "O'Connell Street"])->name);
    }
}
