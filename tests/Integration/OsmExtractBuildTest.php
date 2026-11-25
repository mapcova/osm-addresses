<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Integration;

use MapCova\OsmAddresses\Build\BuildState;
use MapCova\OsmAddresses\Build\DatasetBuilder;
use MapCova\OsmAddresses\Build\PermanentBuildException;
use MapCova\OsmAddresses\Tests\Support\BuildKit;
use MapCova\OsmAddresses\Tests\Support\FakeOsmSource;
use MapCova\OsmAddresses\Tests\Support\OsmExtractFixtures;
use MapCova\OsmAddresses\Tests\Support\RangeHttp;
use MapCova\OsmAddresses\Tests\Support\Sql;
use MapCova\OsmAddresses\Tests\TestCase;

/**
 * The extraction phase in a build, over a synthetic extract served the way Geofabrik does (`-latest` redirected to a dated
 * file, with its `.md5`): built continuously and resumed after every unit, one blob at a time, it gives the same database.
 */
final class OsmExtractBuildTest extends TestCase
{
    private function build(string $dir, int $extra, int $blobs = 1, ?RangeHttp $http = null, ?\Closure $after = null): BuildState
    {
        [$state] = BuildKit::build(new FakeOsmSource(), $http ?? OsmExtractFixtures::http(OsmExtractFixtures::writer()), $dir, $extra, afterCheckpoint: $after, extractBlobs: $blobs);

        return $state;
    }

    public function testBuildsADatabaseFromAnExtract(): void
    {
        $dir = $this->tempDir();
        $state = $this->build($dir, PHP_INT_MAX, 256);

        self::assertSame(BuildState::DONE, $state->phase);
        $dump = BuildKit::dump($dir . '/gb.sqlite');

        self::assertSame((string) OsmExtractFixtures::ADDRESSES, $dump['meta']['rows']);
        self::assertSame('0', $dump['meta']['rejected']);
        self::assertSame('fake-osm', $dump['meta']['source']);
        self::assertSame('ODbL 1.0', $dump['meta']['licence']);
        self::assertSame('0', $dump['meta']['trigram']);

        $db = Sql::open($dir . '/gb.sqlite');
        // The square building and the address node of High Street, one street; Mill Lane of London has the list and the L shape,
        // that of Westminster the range, and the named houses are streets of their own.
        self::assertSame('2', Sql::value($db, "SELECT address_count FROM street WHERE name = 'High Street'"));
        self::assertSame('4', Sql::value($db, "SELECT address_count FROM street WHERE name = 'Mill Lane' AND city = 'London'"));
        self::assertSame('1', Sql::value($db, "SELECT address_count FROM street WHERE name = 'Mill Lane' AND city = 'Westminster'"));
        self::assertEqualsWithDelta(51.5001, (float) Sql::value($db, "SELECT lat_e6 FROM address WHERE number = '12'") / 1e6, 0.000001);
        // The address without a city takes the name of the hamlet next to it.
        self::assertSame('Little Hamlet', Sql::value($db, "SELECT city FROM street WHERE name = 'Little Hamlet'"));
        // The extract, its working files and the build folder's leftovers are gone.
        self::assertSame([], glob($dir . '/.build/gb-*') ?: []);
    }

    public function testTheRedirectedDatedFileIsDownloadedAndItsChecksumRead(): void
    {
        $http = OsmExtractFixtures::http(OsmExtractFixtures::writer());
        $this->build($this->tempDir(), PHP_INT_MAX, 256, $http);

        $gets = array_values(array_filter($http->requests, static fn (string $r): bool => str_starts_with($r, 'GET ')));
        self::assertContains('GET ' . OsmExtractFixtures::DATED_URL . '.md5', $gets);
        self::assertSame([], array_filter($gets, static fn (string $r): bool => str_contains($r, 'fake-latest') && str_contains($r, 'bytes=')), 'the ranges are fetched from the dated file');
        self::assertNotSame([], array_filter($gets, static fn (string $r): bool => str_starts_with($r, 'GET ' . OsmExtractFixtures::DATED_URL . ' bytes=')));
    }

    public function testResumedAfterEveryUnitIsIdenticalToAContinuousBuild(): void
    {
        $continuous = $this->tempDir();
        $this->build($continuous, PHP_INT_MAX, 256);

        $resumed = $this->tempDir();
        $phases = [];
        $memory = 0;
        [, $runs] = BuildKit::build(new FakeOsmSource(), OsmExtractFixtures::http(OsmExtractFixtures::writer()), $resumed, 0, afterCheckpoint: static function (BuildState $s) use (&$phases, &$memory): void {
            $phases[$s->phase] = ($phases[$s->phase] ?? 0) + 1;
            $memory = max($memory, memory_get_peak_usage(true));
        }, extractBlobs: 1);

        // The extraction alone is 29 units of one blob.
        self::assertGreaterThan(25, $phases[BuildState::EXTRACT]);
        self::assertGreaterThan(60, $runs);
        self::assertLessThan(64 << 20, $memory);
        self::assertSame(BuildKit::dump($continuous . '/gb.sqlite'), BuildKit::dump($resumed . '/gb.sqlite'));
    }

    public function testGarbageLeftByAKilledUnitIsCutOffAtEveryResume(): void
    {
        $continuous = $this->tempDir();
        $this->build($continuous, PHP_INT_MAX, 256);

        $dir = $this->tempDir();
        $state = $this->build($dir, 0, 1, null, static function (BuildState $s) use ($dir): void {
            $state = glob($dir . '/.build/gb-*.chunk.osm.json') ?: [];

            // A unit is killed before its checkpoint: once the extraction is complete there is nothing left to kill.
            if ($s->phase !== BuildState::EXTRACT || $state === [] || str_contains((string) file_get_contents($state[0]), '"step":"done"')) {
                return;
            }

            // What a unit killed before its checkpoint wrote: bytes past the recorded end of every output, and of the bucket files.
            foreach (glob($dir . '/.build/gb-*.chunk.*') ?: [] as $file) {
                if (preg_match('/\.(addr|ways|places|names)\.tsv$|\.bucket-\d+\.bin$/', $file) === 1) {
                    file_put_contents($file, "garbage\tgarbage\n", FILE_APPEND);
                }
            }
        });

        self::assertSame(BuildState::DONE, $state->phase);
        self::assertSame(BuildKit::dump($continuous . '/gb.sqlite'), BuildKit::dump($dir . '/gb.sqlite'));
    }

    public function testAnExtractThatVanishesIsDownloadedAgain(): void
    {
        $continuous = $this->tempDir();
        $this->build($continuous, PHP_INT_MAX, 256);

        $dir = $this->tempDir();
        $gone = false;
        $this->build($dir, 0, 1, null, static function (BuildState $s) use ($dir, &$gone): void {
            $file = glob($dir . '/.build/gb-*.chunk') ?: [];
            $units = glob($dir . '/.build/gb-*.chunk.run-*') ?: [];

            // After a few units of the scan the build folder loses the extract (the host cleaned it).
            if (!$gone && $s->phase === BuildState::EXTRACT && $file !== [] && count($units) >= 3) {
                $gone = true;
                unlink($file[0]);
            }
        });

        self::assertTrue($gone);
        self::assertSame(BuildKit::dump($continuous . '/gb.sqlite'), BuildKit::dump($dir . '/gb.sqlite'));
    }

    public function testADamagedExtractIsRefused(): void
    {
        $http = OsmExtractFixtures::http(OsmExtractFixtures::writer())->serve(OsmExtractFixtures::DATED_URL . '.md5', str_repeat('0', 32) . "  fake-261124.osm.pbf\n");

        try {
            $this->build($this->tempDir(), PHP_INT_MAX, 256, $http);
            self::fail('a checksum that differs must stop the build');
        } catch (PermanentBuildException $e) {
            self::assertSame('err_osm_md5', $e->key());
        }
    }

    public function testAMissingChecksumFileIsNotAnError(): void
    {
        $http = OsmExtractFixtures::http(OsmExtractFixtures::writer())->gone(OsmExtractFixtures::DATED_URL . '.md5');

        self::assertSame(BuildState::DONE, $this->build($this->tempDir(), PHP_INT_MAX, 256, $http)->phase);
    }

    public function testTheProgressGrowsThroughTheExtraction(): void
    {
        $done = [];
        $this->build($this->tempDir(), 0, 1, null, static function (BuildState $s) use (&$done): void {
            if ($s->phase === BuildState::EXTRACT) {
                $done[] = DatasetBuilder::progress($s)[0];
            }
        });

        $sorted = $done;
        sort($sorted);
        self::assertSame($sorted, $done, 'the progress never goes back');
        self::assertGreaterThan($done[0], $done[count($done) - 1]);
    }
}
