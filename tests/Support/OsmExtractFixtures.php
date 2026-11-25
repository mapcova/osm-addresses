<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Support;

/**
 * A synthetic OpenStreetMap extract around London: building ways (a square and an L shape), address nodes, places, a street
 * with a Welsh name, objects the extraction must drop, and a relation. Nodes 1 to 31 and ways 101 to 108, so that blocks
 * of two or three objects give a file of many blobs.
 */
final class OsmExtractFixtures
{
    /** The addresses the extraction writes: 4 nodes (one a list of three numbers) and 4 building ways, one of them a named house. */
    public const ADDRESSES = 10;

    public const URL = 'https://download.fake-osm.test/europe/fake-latest.osm.pbf';

    public const DATED_URL = 'https://download.fake-osm.test/europe/fake/fake-261124.osm.pbf';

    public static function writer(int $nodesPerBlock = 3, int $waysPerBlock = 2, bool $dense = true): PbfWriter
    {
        $w = new PbfWriter($nodesPerBlock, $waysPerBlock, $dense);

        // A square: its centre is the mean of its four corners.
        $w->node(1, 51.5000, -0.1000)->node(2, 51.5000, -0.0998)->node(3, 51.5002, -0.0998)->node(4, 51.5002, -0.1000);
        // An L shape (in 1e-4 degrees: 0,0 0,2 1,2 1,1 2,1 2,0): the centre is the mean of the corners 1, 2, 4 and 5 of its ring.
        $w->node(5, 51.6000, -0.2000)->node(6, 51.6000, -0.1998)->node(7, 51.6001, -0.1998)->node(8, 51.6001, -0.1999)->node(9, 51.6002, -0.1999)->node(10, 51.6002, -0.2000);
        $w->node(11, 51.5100, -0.1200, ['addr:housenumber' => '10A', 'addr:street' => 'High Street', 'addr:postcode' => 'sw1a 1aa', 'addr:city' => 'London']);
        $w->node(12, 51.5110, -0.1210, ['addr:housenumber' => '2;4, 6', 'addr:street' => 'Mill Lane', 'addr:postcode' => 'SW1A 1AB', 'addr:town' => 'London']);
        $w->node(13, 51.5120, -0.1220, ['addr:housename' => 'Rose Cottage', 'addr:street' => 'Mill Lane', 'addr:city' => 'London']);
        // Dropped: a number that is none, and no street.
        $w->node(14, 51.5130, -0.1230, ['addr:housenumber' => 'Flat 2', 'addr:street' => 'X Road', 'addr:city' => 'London']);
        $w->node(15, 51.5140, -0.1240, ['addr:housenumber' => '5', 'addr:city' => 'London']);
        // No city: the place of the hamlet names it.
        $w->node(16, 51.7000, -0.3000, ['addr:housenumber' => '7', 'addr:place' => 'Little Hamlet']);
        $w->node(17, 51.5150, -0.1250, ['shop' => 'bakery', 'name' => 'Baker']);
        $w->node(20, 51.5000, -0.1000, ['place' => 'city', 'name' => 'London', 'name:cy' => 'Llundain']);
        $w->node(21, 51.5100, -0.5900, ['place' => 'town', 'name' => 'Slough']);
        $w->node(22, 51.7010, -0.3010, ['place' => 'hamlet', 'name' => 'Little Hamlet']);
        $w->node(23, 51.5200, -0.1300, ['place' => 'suburb', 'name' => 'Soho']);
        $w->node(30, 51.8000, -0.4000)->node(31, 51.8010, -0.4000);

        $w->way(101, [1, 2, 3, 4, 1], ['addr:housenumber' => '12', 'addr:street' => 'High Street', 'addr:postcode' => 'SW1A 1AB', 'addr:city' => 'London', 'building' => 'yes']);
        $w->way(102, [5, 6, 7, 8, 9, 10, 5], ['addr:housenumber' => '14', 'addr:street' => 'Mill Lane', 'addr:city' => 'London']);
        // Its nodes are not in the file.
        $w->way(103, [900, 901], ['addr:housenumber' => '1', 'addr:street' => 'Ghost Road', 'addr:city' => 'London']);
        $w->way(104, [30, 31], ['highway' => 'residential', 'name' => 'High Road', 'name:cy' => 'Ffordd Fawr']);
        $w->way(105, [30, 31], ['highway' => 'residential', 'name' => 'Plain Road']);
        $w->way(106, [1, 3], ['addr:housenumber' => '2-6', 'addr:street' => 'Mill Lane', 'addr:postcode' => 'SW1A 1AC', 'addr:city' => 'Westminster']);
        $w->way(107, [1, 2, 3, 4, 1], ['addr:housenumber' => '9', 'addr:city' => 'London']);
        $w->way(108, [5, 6, 7, 5], ['addr:housename' => 'The Old Mill', 'addr:street' => 'Mill Lane', 'addr:city' => 'London']);
        // Relations are not read.
        $w->relation(201, ['addr:housenumber' => '3', 'addr:street' => 'High Street', 'type' => 'multipolygon'], [['outer', 1, 101]]);

        return $w;
    }

    /**
     * The lines of an extracted file as records keyed by the header, in the order of the file.
     *
     * @return list<array<string,string>>
     */
    public static function records(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $header = explode("\t", (string) array_shift($lines));

        return array_map(static fn (string $line): array => array_combine($header, array_pad(explode("\t", $line), count($header), '')), $lines);
    }

    /**
     * The files an extraction leaves, by name, with their content (to compare two extractions).
     *
     * @return array<string,string|false>
     */
    public static function outputs(string $chunkPath): array
    {
        $out = [];

        foreach (['addr.tsv', 'places.tsv', 'names.tsv', 'postcity.tsv'] as $name) {
            $out[$name] = @file_get_contents($chunkPath . '.' . $name);
        }

        return $out;
    }

    public static function http(PbfWriter $writer): RangeHttp
    {
        $body = $writer->contents();

        return (new RangeHttp())
            ->serve(self::URL, $body, '"88a1b509-6610eff23eed6"', 'Wed, 25 Nov 2026 00:34:58 GMT')
            ->redirect(self::URL, self::DATED_URL)
            ->serve(self::DATED_URL . '.md5', md5($body) . "  fake-261124.osm.pbf\n");
    }
}
