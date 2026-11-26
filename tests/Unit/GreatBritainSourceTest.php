<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Unit;

use MapCova\OsmAddresses\Build\SourceFormatException;
use MapCova\OsmAddresses\Sources\AddressRow;
use MapCova\OsmAddresses\Sources\GreatBritainSource;
use MapCova\OsmAddresses\Tests\Support\OsmFixtures;
use MapCova\OsmAddresses\Tests\TestCase;

final class GreatBritainSourceTest extends TestCase
{
    /** @param array<string,string> $values */
    private function row(array $values, ?GreatBritainSource $source = null): ?AddressRow
    {
        return OsmFixtures::row($source ?? new GreatBritainSource(), $values);
    }

    public function testTheSourceDescribesItself(): void
    {
        $source = new GreatBritainSource();

        self::assertSame(['GB', 'osm', 'ODbL 1.0', 'https://opendatacommons.org/licenses/odbl/1-0/'], [$source->country(), $source->id(), $source->licence(), $source->licenceUrl()]);
        self::assertSame('© OpenStreetMap contributors, ODbL 1.0 — https://www.openstreetmap.org/copyright', $source->attribution());
        self::assertFalse($source->hasTrigram());
        self::assertTrue($source->dedupeHouse());
        self::assertSame(['cy', 'gd'], $source->languages());
        self::assertSame('plain', $source->chunks()[0]->format);
        self::assertSame($source->versionUrl(), $source->chunks()[0]->url);
    }

    public function testAMissingColumnIsRefused(): void
    {
        $this->expectException(SourceFormatException::class);
        (new GreatBritainSource())->columns(['id', 'lat_e6']);
    }

    public function testEveryFormOfANumber(): void
    {
        self::assertSame(['10', null], [$this->row(['number' => '10'])?->number, $this->row(['number' => '10'])?->suffix]);
        self::assertSame(['10', 'a'], [$this->row(['number' => '10a'])?->number, $this->row(['number' => '10a'])?->suffix]);
        self::assertSame(['2', '-6'], [$this->row(['number' => '2-6'])?->number, $this->row(['number' => '2-6'])?->suffix]);
        self::assertSame(['12', '/14'], [$this->row(['number' => '12/14'])?->number, $this->row(['number' => '12/14'])?->suffix]);
        self::assertSame('7', $this->row(['number' => '007'])?->number);
        self::assertNull($this->row(['number' => '0']));
        self::assertNull($this->row(['number' => 'Flat 2']));
    }

    public function testANamedHouseIsAStreetWithOneAddressAndNoNumber(): void
    {
        $row = $this->row(['housename' => 'Rose Cottage', 'street' => 'Mill Lane']);

        self::assertSame(['Rose Cottage, Mill Lane', null, null], [$row?->name, $row?->number, $row?->suffix]);
        self::assertNull($this->row(['housename' => '', 'street' => 'Mill Lane']), 'neither a number nor a name');
    }

    public function testAPlaceStandsForTheStreet(): void
    {
        $row = $this->row(['number' => '7', 'street' => '', 'place' => 'Pen-y-Bryn']);

        self::assertSame(['Pen-y-Bryn', '7'], [$row?->name, $row?->number]);
        self::assertNull($this->row(['number' => '7', 'street' => '', 'place' => '']));
        self::assertSame('Pen-y-Bryn, Mill Lane', $this->row(['housename' => 'Pen-y-Bryn', 'street' => 'Mill Lane'])?->name);
    }

    public function testPostcodesAreSplitInOutwardAndInwardCodes(): void
    {
        foreach (['SW1A 1AA' => ['SW1A', '1AA'], 'sw1a1aa' => ['SW1A', '1AA'], ' cf10  1ep ' => ['CF10', '1EP'], 'M1 1AE' => ['M1', '1AE'], 'B33 8TH' => ['B33', '8TH'], 'EC1A 1BB' => ['EC1A', '1BB'], 'BT1 5GS' => ['BT1', '5GS'], 'W1A 0AX' => ['W1A', '0AX']] as $text => [$outward, $inward]) {
            $row = $this->row(['number' => '1', 'postcode' => (string) $text]);
            self::assertSame([$outward, $inward], [$row?->postcode, $row?->pc], (string) $text);
        }
    }

    public function testAnInvalidPostcodeIsNoPostcodeAndTheAddressStays(): void
    {
        $source = new GreatBritainSource();

        foreach (['GIR 0AA', 'IM1 1AA', 'JE2 3AB', 'GY1 1AA', 'ZZ9 9ZZ', 'ZZ1', '12345', 'SW1A 1A', 'SW1A 11A', 'SW1AA 1AA'] as $text) {
            $row = $this->row(['number' => '1', 'postcode' => $text], $source);
            self::assertNotNull($row, $text);
            self::assertSame([null, null], [$row->postcode, $row->pc], $text);
        }

        self::assertSame(10, $source->tallies()['bad_postcode']);
    }

    public function testAnOutwardCodeAloneIsAStreetPostcodeWithNoInwardCode(): void
    {
        foreach (['SW1A' => 'SW1A', ' cf10 ' => 'CF10', 'M1' => 'M1', 'B33' => 'B33'] as $text => $outward) {
            $row = $this->row(['number' => '1', 'postcode' => (string) $text]);
            self::assertSame([$outward, null], [$row?->postcode, $row?->pc], (string) $text);
        }

        self::assertSame('SW1A', (new GreatBritainSource())->postcodeKey('SW1A 1AA'));
        self::assertSame('SW1A', (new GreatBritainSource())->postcodeKey('SW1A'));
        self::assertNull((new GreatBritainSource())->postcodeKey('GIR 0AA'));
    }

    public function testTheCityComesFromTheTagsThenThePostcodeThenTheNearestPlace(): void
    {
        $dir = $this->tempDir();
        $source = new GreatBritainSource();
        // The address is 0.8 km from Cardiff; the hamlet is 6 km from it (radius 1.5 km).
        OsmFixtures::load($source, $dir, [['city', 'Cardiff', 51_482_000, -3_175_000, '', ''], ['hamlet', 'Little Hamlet', 51_540_000, -3_175_000, '', '']], [], ['CF10' => 'Penarth']);

        self::assertSame('Rhiwbina', $this->row(['number' => '1', 'city' => 'Rhiwbina', 'postcode' => 'CF10 1AA'], $source)?->city, 'the tag');
        self::assertSame('Penarth', $this->row(['number' => '1', 'city' => '', 'postcode' => 'CF10 1AA'], $source)?->city, 'the post town');
        self::assertSame('Cardiff', $this->row(['number' => '1', 'city' => '', 'postcode' => 'CF99 1AA'], $source)?->city, 'no post town for this outward code: the nearest place');
        self::assertSame('Cardiff', $this->row(['number' => '1', 'city' => '', 'postcode' => ''], $source)?->city);
        // 1.7 km from the hamlet (radius 1.5 km) and 4.8 km from the city (radius 15 km): the city; on the hamlet: the hamlet.
        self::assertSame('Cardiff', $this->row(['number' => '1', 'city' => '', 'lat_e6' => '51525000'], $source)?->city);
        self::assertSame('Little Hamlet', $this->row(['number' => '1', 'city' => '', 'lat_e6' => '51540000'], $source)?->city);
        // Beyond every radius: rejected.
        self::assertNull($this->row(['number' => '1', 'city' => '', 'lat_e6' => '52500000'], $source));
        self::assertSame(1, $source->tallies()['no_city']);
        self::assertEquals(['numbered' => 7, 'no_postcode' => 4, 'city_from_postcode' => 1, 'city_from_place' => 4], array_diff_key($source->tallies(), ['no_city' => 1]));
    }

    public function testTheCityCodeIsTheNormalisedName(): void
    {
        $row = $this->row(['number' => '1', 'city' => 'King’s Lynn']);

        self::assertSame(['King’s Lynn', 'king s lynn'], [$row?->city, $row?->cityCode]);
    }

    public function testTheDistrictIsTheSuburbThenTheVillageOrHamletWhenItIsNotTheCity(): void
    {
        self::assertSame('Roath', $this->row(['number' => '1', 'suburb' => 'Roath', 'village' => 'Elsewhere'])?->district);
        self::assertSame('Llanishen', $this->row(['number' => '1', 'village' => 'Llanishen', 'hamlet' => 'Elsewhere'])?->district);
        self::assertSame('Cyncoed', $this->row(['number' => '1', 'hamlet' => 'Cyncoed'])?->district);
        self::assertNull($this->row(['number' => '1', 'suburb' => 'cardiff'])?->district, 'the city is no district');
        self::assertSame('Cyncoed', $this->row(['number' => '1', 'suburb' => 'Cardiff', 'hamlet' => 'Cyncoed'])?->district);
        self::assertNull($this->row(['number' => '1'])?->district);
    }

    public function testTheBorderIsKeptOnBothSides(): void
    {
        // Belfast and Derry are Northern Ireland: Great Britain's. Dundalk, Letterkenny and Lifford are the Republic's.
        self::assertNotNull($this->row(['number' => '1', 'lat_e6' => '54597300', 'lon_e6' => '-5930100']));
        self::assertNotNull($this->row(['number' => '1', 'lat_e6' => '54996600', 'lon_e6' => '-7308600']));
        self::assertNull($this->row(['number' => '1', 'lat_e6' => '54000000', 'lon_e6' => '-6416700']));
        self::assertNull($this->row(['number' => '1', 'lat_e6' => '54950000', 'lon_e6' => '-7733300']));
        self::assertNull($this->row(['number' => '1', 'lat_e6' => '54832900', 'lon_e6' => '-7482400']));
        // Kintyre is further north than the island and in Scotland; Shetland is in; Paris is out of the box.
        self::assertNotNull($this->row(['number' => '1', 'lat_e6' => '55420000', 'lon_e6' => '-5600000']));
        self::assertNotNull($this->row(['number' => '1', 'lat_e6' => '60150000', 'lon_e6' => '-1150000']));
        self::assertNull($this->row(['number' => '1', 'lat_e6' => '48850000', 'lon_e6' => '2350000']));
    }

    public function testTheOtherLanguagesGoToNamesAndToTheSearchText(): void
    {
        $dir = $this->tempDir();
        $source = new GreatBritainSource();
        OsmFixtures::load(
            $source,
            $dir,
            [['city', 'Cardiff', 51_481_600, -3_179_100, 'Caerdydd', '']],
            [['Queen Street', 51_482_300, -3_175_000, 'Heol y Frenhines', '']],
        );

        $row = $this->row(['number' => '10', 'street' => 'Queen Street', 'lat_e6' => '51482400', 'lon_e6' => '-3174800'], $source);

        self::assertSame('{"cy":["Heol y Frenhines","Caerdydd"]}', $row?->names);
        self::assertSame('Heol y Frenhines Caerdydd', $row->extra);

        // A street with no form of its own, in a city that has one: the city's, with the street as it is.
        $plain = $this->row(['number' => '10', 'street' => 'Plain Road'], $source);
        self::assertSame('{"cy":["Plain Road","Caerdydd"]}', $plain?->names);
        self::assertSame('Caerdydd', $plain->extra);

        // Too far from the street (more than a cell away): no form.
        $far = $this->row(['number' => '10', 'street' => 'Queen Street', 'lat_e6' => '52500000'], $source);
        self::assertNotNull($far);
        self::assertNull($far->names, 'no street form, no city form');
        self::assertSame('', $far->extra);
    }

    public function testTheNameWithoutApostrophesIsSearchable(): void
    {
        $row = $this->row(['number' => '5', 'street' => "St John's Road", 'city' => "King's Lynn"]);

        self::assertSame('St Johns Road Kings Lynn', $row?->extra);
        self::assertNull($row->names);
        self::assertSame('Roses Cottage, Mill Lane', $this->row(['housename' => 'Rose’s Cottage', 'street' => 'Mill Lane'])?->extra, 'the curly apostrophe too');
        self::assertSame('', $this->row(['number' => '5', 'street' => 'High Street'])?->extra);
    }

    public function testAHouseNameHasItsApostropheVariantWithTheStreet(): void
    {
        $row = $this->row(['housename' => "Farmer's Rest", 'street' => 'Mill Lane']);

        self::assertSame('Farmers Rest, Mill Lane', $row?->extra);
    }

    public function testATextTooLongIsRejected(): void
    {
        self::assertNull($this->row(['number' => '1', 'street' => str_repeat('x', 201)]));
    }
}
