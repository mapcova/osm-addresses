<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Integration;

use MapCova\OsmAddresses\Build\BuildState;
use MapCova\OsmAddresses\Sources\GreatBritainSource;
use MapCova\OsmAddresses\Tests\Support\BuildKit;
use MapCova\OsmAddresses\Tests\Support\OsmFixtures;
use MapCova\OsmAddresses\Tests\Support\RangeHttp;
use MapCova\OsmAddresses\Tests\Support\Sql;
use MapCova\OsmAddresses\Tests\TestCase;

/**
 * The United Kingdom built end to end from a synthetic extract served the way Geofabrik does (`-latest` redirected to a dated
 * file): the database, and the same database when resumed after every unit.
 */
final class GreatBritainBuildTest extends TestCase
{
    private const DATED = 'https://download.geofabrik.de/europe/united-kingdom/united-kingdom-261124.osm.pbf';

    private function http(): RangeHttp
    {
        return OsmFixtures::http(OsmFixtures::gb(), GreatBritainSource::URL, self::DATED);
    }

    private function build(string $dir, int $extra, int $blobs = 256): BuildState
    {
        [$state] = BuildKit::build(new GreatBritainSource(), $this->http(), $dir, $extra, extractBlobs: $blobs);

        return $state;
    }

    public function testBuildsTheDatabase(): void
    {
        $dir = $this->tempDir();
        $state = $this->build($dir, PHP_INT_MAX);

        self::assertSame(BuildState::DONE, $state->phase);
        $dump = BuildKit::dump($dir . '/gb.sqlite');

        self::assertSame('GB', $dump['meta']['country']);
        self::assertSame((string) OsmFixtures::GB_ROWS, $dump['meta']['rows']);
        self::assertSame((string) OsmFixtures::GB_REJECTED, $dump['meta']['rejected']);
        self::assertSame('osm', $dump['meta']['source']);
        self::assertSame('ODbL 1.0', $dump['meta']['licence']);
        self::assertSame('https://opendatacommons.org/licenses/odbl/1-0/', $dump['meta']['licence_url']);
        self::assertSame('© OpenStreetMap contributors, ODbL 1.0 — https://www.openstreetmap.org/copyright', $dump['meta']['attribution']);
        self::assertSame('0', $dump['meta']['trigram']);
        self::assertSame('1', $dump['meta']['text_rules']);

        $db = Sql::open($dir . '/gb.sqlite');
        // Queen Street (two numbers, one with its city from the post town), St John's Street (a list of three), Mill Lane (the square
        // and the range), Rose Cottage and Pen-y-Bryn (cities from the places), Grange Road (no valid postcode), Donegall Square (Belfast).
        self::assertSame('7', $dump['meta']['streets']);
        self::assertSame('2', Sql::value($db, "SELECT address_count FROM street WHERE name = 'Queen Street'"));
        self::assertSame('CF10', Sql::value($db, "SELECT postcode FROM street WHERE name = 'Queen Street'"));
        self::assertSame('Cardiff', Sql::value($db, "SELECT city FROM street WHERE name = 'Queen Street'"));
        self::assertSame('3', Sql::value($db, "SELECT address_count FROM street WHERE name = 'St John''s Street'"));
        self::assertSame('2', Sql::value($db, "SELECT address_count FROM street WHERE name = 'Mill Lane' AND city = 'Cardiff'"));
        self::assertSame('1GL', Sql::value($db, "SELECT pc FROM address WHERE number = '4'"));
        self::assertSame('-6', Sql::value($db, "SELECT suffix FROM address WHERE number = '2' AND pc = '1EP'"));
        self::assertEqualsWithDelta(51.4801, (float) Sql::value($db, "SELECT lat_e6 FROM address WHERE number = '12'") / 1e6, 0.000001);
        self::assertEqualsWithDelta(-3.1799, (float) Sql::value($db, "SELECT lon_e6 FROM address WHERE number = '12'") / 1e6, 0.000001);
        self::assertSame('Little Hamlet', Sql::value($db, "SELECT city FROM street WHERE name = 'Rose Cottage, Mill Lane'"));
        self::assertSame('Pen-y-Bryn', Sql::value($db, "SELECT city FROM street WHERE name = 'Pen-y-Bryn'"));
        self::assertSame('', Sql::value($db, "SELECT COALESCE(postcode, '') FROM street WHERE name = 'Grange Road'"));
        self::assertSame('BT1', Sql::value($db, "SELECT postcode FROM street WHERE name = 'Donegall Square'"));
        self::assertSame('0', Sql::value($db, "SELECT COUNT(*) FROM street WHERE name = 'Clanbrassil Street' OR name = 'Lonely Road'"), 'the Republic and the highlands are out');
        self::assertSame('1', Sql::value($db, "SELECT COUNT(*) FROM address WHERE number IS NULL AND suffix IS NULL AND id IN (SELECT first_address FROM street WHERE name = 'Rose Cottage, Mill Lane')"));
        self::assertSame('{"cy":["Heol y Frenhines","Caerdydd"]}', Sql::value($db, "SELECT names FROM street WHERE name = 'Queen Street'"));
        // The search text: the Welsh forms and the name without its apostrophe.
        self::assertSame('1', Sql::value($db, "SELECT COUNT(*) FROM street_fts WHERE street_fts MATCH '\"johns\"'"));
        self::assertSame('1', Sql::value($db, "SELECT COUNT(*) FROM street_fts WHERE street_fts MATCH '\"frenhines\"'"));
        self::assertSame([], glob($dir . '/.build/gb-*') ?: []);
    }

    public function testResumedAfterEveryUnitIsIdenticalToAContinuousBuild(): void
    {
        $continuous = $this->tempDir();
        $this->build($continuous, PHP_INT_MAX);

        $resumed = $this->tempDir();
        $phases = [];
        [, $runs] = BuildKit::build(new GreatBritainSource(), $this->http(), $resumed, 0, afterCheckpoint: static function (BuildState $s) use (&$phases): void {
            $phases[$s->phase] = ($phases[$s->phase] ?? 0) + 1;
        }, extractBlobs: 1);

        self::assertGreaterThan(10, $phases[BuildState::EXTRACT]);
        self::assertGreaterThan(30, $runs);
        self::assertSame(BuildKit::dump($continuous . '/gb.sqlite'), BuildKit::dump($resumed . '/gb.sqlite'));
    }
}
