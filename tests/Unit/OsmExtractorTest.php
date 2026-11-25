<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Unit;

use MapCova\OsmAddresses\Build\PermanentBuildException;
use MapCova\OsmAddresses\Osm\AddressTags;
use MapCova\OsmAddresses\Osm\OsmExtractor;
use MapCova\OsmAddresses\Tests\Support\OsmExtractFixtures;
use MapCova\OsmAddresses\Tests\Support\PbfWriter;
use MapCova\OsmAddresses\Tests\TestCase;

/** The extraction of the synthetic extract: what is kept, the centres of ways, and that every way of running it gives the same files. */
final class OsmExtractorTest extends TestCase
{
    /** @return array{string,string} the folder and the path of the extract in it */
    private function extract(?PbfWriter $writer = null): array
    {
        $dir = $this->tempDir();
        ($writer ?? OsmExtractFixtures::writer())->write($dir . '/x.pbf');

        return [$dir, $dir . '/x.pbf'];
    }

    /**
     * @param (callable(int):bool)|null $allow whether to run the unit of this number
     *
     * @return int units run
     */
    private function drive(OsmExtractor $extractor, ?callable $allow = null, ?string $checksum = null): int
    {
        $units = 0;
        $extractor->run(static function (float $cost) use ($allow, &$units): bool {
            return $allow === null || $allow($units);
        }, static function () use (&$units): void {
            ++$units;
        }, $checksum);

        return $units;
    }

    /** @return array<string,string|false> */
    private function continuous(): array
    {
        [, $path] = $this->extract();
        $this->drive(new OsmExtractor($path, ['cy', 'ga'], 1000));

        return OsmExtractFixtures::outputs($path);
    }

    public function testKeepsAddressesAndGivesWaysTheCentreOfTheirNodes(): void
    {
        [, $path] = $this->extract();
        $extractor = new OsmExtractor($path, ['cy', 'ga'], 1000);
        $this->drive($extractor, null, md5_file($path) ?: null);

        $addresses = OsmExtractFixtures::records(OsmExtractor::addressesPath($path));
        $byId = [];

        foreach ($addresses as $record) {
            $byId[$record['id'] . '/' . $record['number']] = $record;
        }

        self::assertCount(OsmExtractFixtures::ADDRESSES, $addresses);
        self::assertSame(AddressTags::HEADER, array_keys($addresses[0]));

        // A node keeps its own position, a square building is centred on its four corners.
        self::assertSame(['51510000', '-120000'], [$byId['n11/10a']['lat_e6'], $byId['n11/10a']['lon_e6']]);
        self::assertSame(['51500100', '-99900'], [$byId['w101/12']['lat_e6'], $byId['w101/12']['lon_e6']]);
        // An L shape: the mean of the corners 1, 2, 4 and 5 of its ring, not of its six.
        self::assertSame(['51600075', '-199900'], [$byId['w102/14']['lat_e6'], $byId['w102/14']['lon_e6']]);
        // A way of two nodes, a triangle (its closing node counts once).
        self::assertSame(['51500100', '-99900'], [$byId['w106/2-6']['lat_e6'], $byId['w106/2-6']['lon_e6']]);
        self::assertSame(['51600033', '-199867'], [$byId['w108/']['lat_e6'], $byId['w108/']['lon_e6']]);

        // A list of numbers is an address for each, at the same point; a named house has no number; `addr:town` is the city.
        foreach (['2', '4', '6'] as $number) {
            self::assertSame(['51511000', 'London', 'Mill Lane'], [$byId['n12/' . $number]['lat_e6'], $byId['n12/' . $number]['city'], $byId['n12/' . $number]['street']]);
        }

        self::assertSame(['Rose Cottage', 'Mill Lane'], [$byId['n13/']['housename'], $byId['n13/']['street']]);
        self::assertSame(['', 'Little Hamlet'], [$byId['n16/7']['street'], $byId['n16/7']['place']]);
        self::assertSame('10a', $byId['n11/10a']['number']);

        // Dropped: a number that is none, no street, a way whose nodes are not in the extract, a relation.
        self::assertArrayNotHasKey('n14/', $byId);
        self::assertArrayNotHasKey('w107/9', $byId);
        self::assertArrayNotHasKey('w103/1', $byId);
        self::assertSame(['addr_nodes' => 4, 'bad_number' => 1, 'no_street' => 2, 'places' => 3, 'addr_ways' => 5, 'named_streets' => 1, 'no_centre' => 1], $extractor->stats());
    }

    public function testCollectsPlacesNamedStreetsAndThePostcodeCities(): void
    {
        [, $path] = $this->extract();
        $this->drive(new OsmExtractor($path, ['cy', 'ga'], 1000));

        self::assertSame([
            ['place' => 'city', 'name' => 'London', 'lat_e6' => '51500000', 'lon_e6' => '-100000', 'cy' => 'Llundain', 'ga' => ''],
            ['place' => 'town', 'name' => 'Slough', 'lat_e6' => '51510000', 'lon_e6' => '-590000', 'cy' => '', 'ga' => ''],
            ['place' => 'hamlet', 'name' => 'Little Hamlet', 'lat_e6' => '51701000', 'lon_e6' => '-301000', 'cy' => '', 'ga' => ''],
        ], OsmExtractFixtures::records(OsmExtractor::placesPath($path)));

        // A street with a name in another language, at its first node; the street without one is not collected.
        self::assertSame([['name' => 'High Road', 'lat_e6' => '51800000', 'lon_e6' => '-400000', 'cy' => 'Ffordd Fawr', 'ga' => '']], OsmExtractFixtures::records(OsmExtractor::namesPath($path)));

        // The most frequent city of an outward code: London (5 addresses) over Westminster (1).
        self::assertSame([['outward' => 'SW1A', 'city' => 'London']], OsmExtractFixtures::records(OsmExtractor::postcityPath($path)));
    }

    public function testTheOutputsAreTheSameWhateverTheSliceOrTheBlockLayout(): void
    {
        $expected = $this->continuous();

        // One blob a unit, one resume after every unit, several buckets of way slots and a run compaction.
        foreach ([[1, 1, 200], [1, 1, 2], [2, 2, 3], [1000, 18, 200]] as [$blobs, $bits, $maxRuns]) {
            [, $path] = $this->extract();

            for ($guard = 0; !$this->isDone($path) && $guard < 500; ++$guard) {
                $this->drive(new OsmExtractor($path, ['cy', 'ga'], $blobs, $bits, $maxRuns, 2), static fn (int $unit): bool => $unit < 1);
            }

            self::assertSame($expected, OsmExtractFixtures::outputs($path), "blobs $blobs, bucket bits $bits, max runs $maxRuns");
            self::assertFileDoesNotExist($path, 'the extract goes with the resolve step');
            self::assertSame([], glob($path . '.run-*') ?: [], 'the runs go too');
            self::assertSame([], glob($path . '.bucket-*') ?: []);
            self::assertFileDoesNotExist($path . '.ways.tsv');
        }
    }

    private function isDone(string $path): bool
    {
        return (new OsmExtractor($path))->step() === 'done';
    }

    public function testPlainNodesGiveTheSameFilesAsDenseOnes(): void
    {
        [, $path] = $this->extract(OsmExtractFixtures::writer(3, 2, false));
        $this->drive(new OsmExtractor($path, ['cy', 'ga'], 1000));

        self::assertSame($this->continuous(), OsmExtractFixtures::outputs($path));
    }

    public function testWhatAKilledUnitLeftBehindIsCutOff(): void
    {
        $expected = $this->continuous();

        // Stop in each phase after a few units, append garbage to every file as a unit killed before its checkpoint would have, and carry on.
        foreach ([3, 12, 16, 22, 26] as $units) {
            [, $path] = $this->extract();
            $this->drive(new OsmExtractor($path, ['cy', 'ga'], 1, 1), static fn (int $unit): bool => $unit < $units);

            foreach (glob($path . '.*') ?: [] as $file) {
                if (!str_ends_with($file, '.osm.json')) {
                    file_put_contents($file, "garbage\tgarbage\n", FILE_APPEND);
                }
            }

            // And an orphan run, as a unit killed after writing it leaves.
            $state = json_decode((string) file_get_contents($path . '.osm.json'), true);
            file_put_contents($path . '.run-' . $state['nextRun'] . '.bin', 'junk');

            $this->drive(new OsmExtractor($path, ['cy', 'ga'], 1, 1));

            self::assertSame($expected, OsmExtractFixtures::outputs($path), "killed after $units units");
        }
    }

    public function testAMissingOrShortWorkFileIsRefused(): void
    {
        [, $path] = $this->extract();
        $this->drive(new OsmExtractor($path, ['cy'], 1, 1), static fn (int $unit): bool => $unit < 8);
        file_put_contents($path . '.addr.tsv', 'x');

        $this->expectException(PermanentBuildException::class);
        $this->expectExceptionMessage('working files');
        $this->drive(new OsmExtractor($path, ['cy'], 1, 1));
    }

    public function testRefusesAnUnsortedFile(): void
    {
        [, $path] = $this->extract(new PbfWriter(sorted: false));

        try {
            $this->drive(new OsmExtractor($path));
            self::fail('an unsorted file must be refused');
        } catch (PermanentBuildException $e) {
            self::assertSame('err_osm_unsorted', $e->key());
        }
    }

    public function testRefusesAnUnsupportedCompression(): void
    {
        [, $path] = $this->extract(new PbfWriter(compression: 'lz4'));

        try {
            $this->drive(new OsmExtractor($path));
            self::fail('lz4 must be refused');
        } catch (PermanentBuildException $e) {
            self::assertSame('err_osm_compression', $e->key());
        }
    }

    public function testRefusesATruncatedFile(): void
    {
        [, $path] = $this->extract();
        file_put_contents($path, substr((string) file_get_contents($path), 0, -9));

        try {
            $this->drive(new OsmExtractor($path, [], 1000));
            self::fail('a truncated file must be refused');
        } catch (PermanentBuildException $e) {
            self::assertSame('err_osm_truncated', $e->key());
        }
    }

    public function testRefusesAFileWhoseChecksumDiffers(): void
    {
        [, $path] = $this->extract();

        try {
            $this->drive(new OsmExtractor($path), null, str_repeat('0', 32));
            self::fail('a damaged file must be refused');
        } catch (PermanentBuildException $e) {
            self::assertSame('err_osm_md5', $e->key());
        }
    }

    public function testRefusesANodeIdBeyondTheKeyRange(): void
    {
        $writer = (new PbfWriter())->node(1, 1.0, 1.0)
            ->way(5, [1, 1 << 36], ['addr:housenumber' => '1', 'addr:street' => 'A Street']);
        [, $path] = $this->extract($writer);

        try {
            $this->drive(new OsmExtractor($path));
            self::fail('an id of 2^36 must be refused');
        } catch (PermanentBuildException $e) {
            self::assertSame('err_osm_id_range', $e->key());
        }
    }

    public function testTheLargestNodeIdOfTheRangeIsAccepted(): void
    {
        $id = (1 << 36) - 1;
        $writer = (new PbfWriter())->node($id, 2.0, 3.0)
            ->way(5, [$id], ['addr:housenumber' => '1', 'addr:street' => 'A Street', 'addr:city' => 'X']);
        [, $path] = $this->extract($writer);
        $this->drive(new OsmExtractor($path));

        self::assertSame([['id' => 'w5', 'lat_e6' => '2000000', 'lon_e6' => '3000000', 'number' => '1']], array_map(
            static fn (array $r): array => array_intersect_key($r, array_flip(['id', 'lat_e6', 'lon_e6', 'number'])),
            OsmExtractFixtures::records(OsmExtractor::addressesPath($path)),
        ));
    }

    public function testAnExtractWithoutAddressesGivesEmptyFiles(): void
    {
        [, $path] = $this->extract((new PbfWriter())->node(1, 1.0, 1.0)->way(2, [1], ['highway' => 'path']));
        $extractor = new OsmExtractor($path);
        $this->drive($extractor);

        self::assertSame('done', $extractor->step());
        self::assertSame([], OsmExtractFixtures::records(OsmExtractor::addressesPath($path)));
    }

    public function testNumbersAreReadInEveryAcceptedForm(): void
    {
        self::assertSame(['10'], AddressTags::numbers('10'));
        self::assertSame(['10a'], AddressTags::numbers(' 10A '));
        self::assertSame(['2-6'], AddressTags::numbers('2 - 6'));
        self::assertSame(['12/14'], AddressTags::numbers('12/14'));
        self::assertSame(['2', '4', '6'], AddressTags::numbers('2;4,6;4'));
        self::assertSame(['1'], AddressTags::numbers('Flat 2;1'));
        self::assertSame([], AddressTags::numbers('Unit 5'));
        self::assertSame([], AddressTags::numbers('123456'));
        self::assertCount(AddressTags::MAX_NUMBERS, AddressTags::numbers(implode(';', range(1, 30))));
    }

    public function testOutwardCodes(): void
    {
        self::assertSame('SW1A', AddressTags::outward('sw1a 1aa'));
        self::assertSame('M1', AddressTags::outward('M1 1AE'));
        self::assertSame('EC1A', AddressTags::outward('EC1A1BB'));
        self::assertNull(AddressTags::outward('D02 X285'));
        self::assertNull(AddressTags::outward('1234'));
    }
}
