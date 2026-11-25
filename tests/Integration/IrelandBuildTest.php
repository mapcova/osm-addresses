<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Integration;

use MapCova\OsmAddresses\Build\BuildState;
use MapCova\OsmAddresses\Sources\IrelandSource;
use MapCova\OsmAddresses\Tests\Support\BuildKit;
use MapCova\OsmAddresses\Tests\Support\OsmFixtures;
use MapCova\OsmAddresses\Tests\Support\RangeHttp;
use MapCova\OsmAddresses\Tests\Support\Sql;
use MapCova\OsmAddresses\Tests\TestCase;

/**
 * Ireland (the Republic) built end to end from a synthetic extract of the island served the way Geofabrik does.
 */
final class IrelandBuildTest extends TestCase
{
    private const DATED = 'https://download.geofabrik.de/europe/ireland-and-northern-ireland/ireland-and-northern-ireland-261124.osm.pbf';

    private function http(): RangeHttp
    {
        return OsmFixtures::http(OsmFixtures::ie(), IrelandSource::URL, self::DATED);
    }

    private function build(string $dir, int $extra, int $blobs = 256): BuildState
    {
        [$state] = BuildKit::build(new IrelandSource(), $this->http(), $dir, $extra, extractBlobs: $blobs);

        return $state;
    }

    public function testBuildsTheDatabase(): void
    {
        $dir = $this->tempDir();
        $state = $this->build($dir, PHP_INT_MAX);

        self::assertSame(BuildState::DONE, $state->phase);
        $dump = BuildKit::dump($dir . '/ie.sqlite');

        self::assertSame('IE', $dump['meta']['country']);
        self::assertSame((string) OsmFixtures::IE_ROWS, $dump['meta']['rows']);
        self::assertSame((string) OsmFixtures::IE_REJECTED, $dump['meta']['rejected']);
        self::assertSame(['osm', 'ODbL 1.0', '0'], [$dump['meta']['source'], $dump['meta']['licence'], $dump['meta']['trigram']]);
        self::assertSame('© OpenStreetMap contributors, ODbL 1.0 — https://www.openstreetmap.org/copyright', $dump['meta']['attribution']);

        $db = Sql::open($dir . '/ie.sqlite');
        self::assertSame('0', Sql::value($db, "SELECT COUNT(*) FROM street WHERE name = 'Hill Street'"), 'Newry is in Northern Ireland');
        self::assertSame('1', Sql::value($db, "SELECT COUNT(*) FROM street WHERE name = 'Clanbrassil Street'"), 'Dundalk is not');
        self::assertSame('T12', Sql::value($db, "SELECT postcode FROM street WHERE name = 'O''Connell Street'"));
        self::assertSame('X285', Sql::value($db, "SELECT pc FROM address WHERE id IN (SELECT first_address FROM street WHERE name = 'O''Connell Street')"));
        self::assertSame('D02', Sql::value($db, "SELECT postcode FROM street WHERE name = 'Main Street' AND city = 'Dublin' AND postcode IS NOT NULL"));
        self::assertSame('2', Sql::value($db, "SELECT address_count FROM street WHERE name = 'Main Street' AND city = 'Kinsale'"));
        self::assertSame('{"ga":["An tSráid Mhór","Cionn tSáile"]}', Sql::value($db, "SELECT names FROM street WHERE name = 'Main Street' AND city = 'Kinsale'"));
        self::assertSame('{"en":["Beach Street","Dingle"]}', Sql::value($db, "SELECT names FROM street WHERE name = 'Sráid na Trá'"));
        self::assertSame('1', Sql::value($db, "SELECT COUNT(*) FROM street_fts WHERE street_fts MATCH '\"oconnell\"'"));
        self::assertSame('1', Sql::value($db, "SELECT COUNT(*) FROM street_fts WHERE street_fts MATCH '\"dingle\"'"));
    }

    public function testResumedAfterEveryUnitIsIdenticalToAContinuousBuild(): void
    {
        $continuous = $this->tempDir();
        $this->build($continuous, PHP_INT_MAX);

        $resumed = $this->tempDir();
        $this->build($resumed, 0, 1);

        self::assertSame(BuildKit::dump($continuous . '/ie.sqlite'), BuildKit::dump($resumed . '/ie.sqlite'));
    }
}
