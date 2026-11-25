<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Support;

use MapCova\OsmAddresses\Osm\AddressTags;
use MapCova\OsmAddresses\Sources\AddressRow;
use MapCova\OsmAddresses\Sources\OsmSource;

/**
 * Synthetic OpenStreetMap extracts of the United Kingdom (Cardiff, Belfast, Dundalk and the highlands) and of Ireland (Cork,
 * Kinsale, Dublin, Dingle, Newry and Dundalk), served the way Geofabrik does: `-latest` redirected to a dated file, with its
 * `.md5`. Blocks of three nodes and two ways, so that a build has many blobs to resume between.
 */
final class OsmFixtures
{
    /** Rows of the United Kingdom's database: 9 from nodes (one a list of three), 2 from building ways. */
    public const GB_ROWS = 11;

    /** One in the Republic, one in the highlands with no place near it. */
    public const GB_REJECTED = 2;

    /** Rows of Ireland's database: 6 from nodes, 1 from a building way. */
    public const IE_ROWS = 7;

    /** The address in Northern Ireland. */
    public const IE_REJECTED = 1;

    public const ETAG = '"88a1b509-6610eff23eed6"';

    public static function writer(): PbfWriter
    {
        return new PbfWriter(3, 2, true);
    }

    public static function gb(): PbfWriter
    {
        $w = self::writer();

        // A square (its centre is the mean of its corners) in Cardiff.
        $w->node(1, 51.4800, -3.1800)->node(2, 51.4800, -3.1798)->node(3, 51.4802, -3.1798)->node(4, 51.4802, -3.1800);
        // The first address of the file: a plain number, a postcode and a city.
        $w->node(11, 51.4824, -3.1748, ['addr:housenumber' => '10', 'addr:street' => 'Queen Street', 'addr:postcode' => 'CF10 2GP', 'addr:city' => 'Cardiff']);
        // A list of three numbers; a postcode in lower case; an apostrophe.
        $w->node(12, 51.4830, -3.1760, ['addr:housenumber' => '2;4, 6', 'addr:street' => "St John's Street", 'addr:postcode' => 'cf10 1gl', 'addr:town' => 'Cardiff']);
        // No city and no postcode: the hamlet next to it names it.
        $w->node(13, 51.8010, -3.5010, ['addr:housename' => 'Rose Cottage', 'addr:street' => 'Mill Lane']);
        // Dropped: a number that is none, and no street.
        $w->node(14, 51.4840, -3.1770, ['addr:housenumber' => 'Flat 2', 'addr:street' => 'X Road', 'addr:city' => 'Cardiff']);
        $w->node(15, 51.4850, -3.1780, ['addr:housenumber' => '5', 'addr:city' => 'Cardiff']);
        // A place instead of a street, and no city: the village names both.
        $w->node(16, 51.9005, -3.7005, ['addr:housenumber' => '7', 'addr:place' => 'Pen-y-Bryn']);
        $w->node(17, 51.4860, -3.1790, ['shop' => 'bakery', 'name' => 'Baker']);
        // A postcode and no city: the post town of the outward code names it.
        $w->node(18, 51.4826, -3.1750, ['addr:housenumber' => '3', 'addr:street' => 'Queen Street', 'addr:postcode' => 'CF10 3AA']);
        // The Isle of Man's postcode is none of ours: the address stays without postcode.
        $w->node(19, 51.4870, -3.1800, ['addr:housenumber' => '9', 'addr:street' => 'Grange Road', 'addr:postcode' => 'IM1 1AA', 'addr:city' => 'Douglas']);
        $w->node(20, 51.4816, -3.1791, ['place' => 'city', 'name' => 'Cardiff', 'name:cy' => 'Caerdydd']);
        $w->node(21, 51.8000, -3.5000, ['place' => 'hamlet', 'name' => 'Little Hamlet']);
        $w->node(22, 51.9000, -3.7000, ['place' => 'village', 'name' => 'Pen-y-Bryn']);
        $w->node(23, 51.4900, -3.1900, ['place' => 'suburb', 'name' => 'Roath']);
        // In the Republic: not Great Britain's. In Northern Ireland: Great Britain's. In the highlands, far from any place.
        $w->node(24, 54.0000, -6.4167, ['addr:housenumber' => '4', 'addr:street' => 'Clanbrassil Street', 'addr:city' => 'Dundalk']);
        $w->node(25, 54.5973, -5.9301, ['addr:housenumber' => '1', 'addr:street' => 'Donegall Square', 'addr:postcode' => 'BT1 5GS', 'addr:city' => 'Belfast', 'addr:suburb' => 'Belfast', 'addr:hamlet' => 'City Centre']);
        $w->node(26, 57.0000, -4.0000, ['addr:housenumber' => '1', 'addr:street' => 'Lonely Road']);
        $w->node(30, 51.4823, -3.1750)->node(31, 51.4820, -3.1745);
        $w->node(32, 54.5970, -5.9300, ['place' => 'city', 'name' => 'Belfast']);

        $w->way(101, [1, 2, 3, 4, 1], ['addr:housenumber' => '12', 'addr:street' => 'Mill Lane', 'addr:postcode' => 'CF10 1EP', 'addr:city' => 'Cardiff', 'building' => 'yes']);
        $w->way(102, [30, 31], ['highway' => 'residential', 'name' => 'Queen Street', 'name:cy' => 'Heol y Frenhines']);
        // Its nodes are not in the file.
        $w->way(103, [900, 901], ['addr:housenumber' => '1', 'addr:street' => 'Ghost Road', 'addr:city' => 'Cardiff']);
        $w->way(104, [1, 3], ['addr:housenumber' => '2-6', 'addr:street' => 'Mill Lane', 'addr:postcode' => 'CF10 1EP', 'addr:city' => 'Cardiff']);
        $w->way(105, [30, 31], ['highway' => 'residential', 'name' => 'Plain Road']);
        // Relations are not read.
        $w->relation(201, ['addr:housenumber' => '3', 'addr:street' => 'High Street', 'type' => 'multipolygon'], [['outer', 1, 101]]);

        return $w;
    }

    public static function ie(): PbfWriter
    {
        $w = self::writer();

        $w->node(1, 51.7000, -8.5200)->node(2, 51.7000, -8.5198)->node(3, 51.7002, -8.5198)->node(4, 51.7002, -8.5200);
        // The first address of the file: no postcode.
        $w->node(11, 51.7060, -8.5220, ['addr:housenumber' => '3', 'addr:street' => 'Main Street', 'addr:city' => 'Kinsale']);
        // An Eircode: routing key on the street, unique identifier on the address.
        $w->node(12, 51.8985, -8.4706, ['addr:housenumber' => '5', 'addr:street' => "O'Connell Street", 'addr:postcode' => 'T12 X285', 'addr:city' => 'Cork']);
        // A postal district written as a postcode; something that is none.
        $w->node(13, 53.3498, -6.2603, ['addr:housenumber' => '1', 'addr:street' => 'Main Street', 'addr:postcode' => 'Dublin 2', 'addr:city' => 'Dublin']);
        $w->node(14, 53.3500, -6.2610, ['addr:housenumber' => '2', 'addr:street' => 'Main Street', 'addr:postcode' => 'XYZ 9', 'addr:city' => 'Dublin']);
        // Irish name, English form: the town's English name is in the place node.
        $w->node(15, 52.1409, -10.2686, ['addr:housenumber' => '8', 'addr:street' => 'Sráid na Trá', 'addr:city' => 'An Daingean']);
        // In Northern Ireland: Great Britain's. In the Republic, near the border: Ireland's.
        $w->node(16, 54.1751, -6.3402, ['addr:housenumber' => '1', 'addr:street' => 'Hill Street', 'addr:city' => 'Newry']);
        $w->node(17, 54.0000, -6.4167, ['addr:housenumber' => '4', 'addr:street' => 'Clanbrassil Street', 'addr:city' => 'Dundalk']);
        $w->node(20, 51.8985, -8.4756, ['place' => 'city', 'name' => 'Cork', 'name:ga' => 'Corcaigh']);
        $w->node(21, 51.7050, -8.5230, ['place' => 'town', 'name' => 'Kinsale', 'name:ga' => 'Cionn tSáile']);
        $w->node(22, 52.1400, -10.2700, ['place' => 'town', 'name' => 'An Daingean', 'name:en' => 'Dingle']);
        $w->node(30, 51.7055, -8.5225)->node(31, 51.7058, -8.5222);
        $w->node(32, 52.1405, -10.2690)->node(33, 52.1408, -10.2688);

        $w->way(101, [1, 2, 3, 4, 1], ['addr:housenumber' => '7', 'addr:street' => 'Main Street', 'addr:city' => 'Kinsale', 'building' => 'yes']);
        $w->way(102, [30, 31], ['highway' => 'residential', 'name' => 'Main Street', 'name:ga' => 'An tSráid Mhór']);
        $w->way(103, [32, 33], ['highway' => 'residential', 'name' => 'Sráid na Trá', 'name:en' => 'Beach Street']);

        return $w;
    }

    public static function http(PbfWriter $writer, string $latest, string $dated): RangeHttp
    {
        $body = $writer->contents();

        return (new RangeHttp())
            ->serve($latest, $body, self::ETAG, 'Wed, 25 Nov 2026 00:34:58 GMT')
            ->redirect($latest, $dated)
            ->serve($dated . '.md5', md5($body) . '  ' . basename($dated) . "\n");
    }

    /**
     * What the extraction leaves for a source to read, written by hand and loaded: places (`place`, `name`, `lat_e6`, `lon_e6`, then
     * a column for each of the source's languages), streets with another name (`name`, `lat_e6`, `lon_e6`, then the languages), and
     * the post towns of outward codes.
     *
     * @param list<list<string|int>> $places
     * @param list<list<string|int>> $streets
     * @param array<string,string>   $postCities
     */
    public static function load(OsmSource $source, string $dir, array $places = [], array $streets = [], array $postCities = []): void
    {
        $tsv = static fn (string $header, array $lines): string => $header . "\n" . implode('', array_map(static fn (array $line): string => implode("\t", $line) . "\n", $lines));
        $base = $dir . '/gb.chunk';
        file_put_contents($base . '.places.tsv', $tsv("place\tname\tlat_e6\tlon_e6\t" . implode("\t", $source->languages()), $places));
        file_put_contents($base . '.names.tsv', $tsv("name\tlat_e6\tlon_e6\t" . implode("\t", $source->languages()), $streets));
        file_put_contents($base . '.postcity.tsv', $tsv("outward\tcity", array_map(null, array_keys($postCities), array_values($postCities))));
        $source->load($base);
    }

    /**
     * One extracted address through a source.
     *
     * @param array<string,string> $values columns of {@see AddressTags::HEADER} (the position defaults to Cardiff)
     */
    public static function row(OsmSource $source, array $values): ?AddressRow
    {
        $values += ['id' => 'n1', 'lat_e6' => '51482000', 'lon_e6' => '-3175000', 'number' => '', 'housename' => '', 'street' => 'High Street', 'place' => '', 'postcode' => '', 'city' => 'Cardiff', 'suburb' => '', 'village' => '', 'hamlet' => ''];

        return $source->row(array_values(array_map(static fn (string $column): string => $values[$column], AddressTags::HEADER)), $source->columns(AddressTags::HEADER));
    }
}
