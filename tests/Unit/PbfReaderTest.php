<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Unit;

use MapCova\OsmAddresses\Osm\OsmTags;
use MapCova\OsmAddresses\Pbf\Block;
use MapCova\OsmAddresses\Pbf\PbfFormatException;
use MapCova\OsmAddresses\Pbf\PbfReader;
use MapCova\OsmAddresses\Tests\Support\PbfWriter;
use MapCova\OsmAddresses\Tests\TestCase;

/** The PBF reader and the block readers of the extraction, on small files written by the test writer. */
final class PbfReaderTest extends TestCase
{
    private function file(PbfWriter $writer): string
    {
        $path = $this->tempDir() . '/x.osm.pbf';
        $writer->write($path);

        return $path;
    }

    private function small(bool $dense = true): PbfWriter
    {
        return (new PbfWriter(2, 1, $dense))
            ->node(10, 51.5, -0.1, ['addr:housenumber' => '1', 'addr:street' => 'High Street'])
            ->node(11, 51.5001, -0.1001)
            ->node(12, -33.8688, 151.2093, ['place' => 'city', 'name' => 'Sydney'])
            ->way(100, [10, 11, 12, 10], ['addr:housenumber' => '2', 'name' => 'ignored'])
            ->way(101, [10, 11], ['highway' => 'service'])
            ->relation(200, ['type' => 'multipolygon'], [['outer', 1, 100]]);
    }

    public function testBlobsAreKeyedByOffsetAndResumeAtABoundary(): void
    {
        $path = $this->file($this->small());
        $all = [];

        foreach (PbfReader::blobs($path) as $at => $blob) {
            $all[$at] = $blob;
        }

        // header, two node blocks (3 nodes, 2 per block), two way blocks, one relation block
        self::assertSame(['OSMHeader', 'OSMData', 'OSMData', 'OSMData', 'OSMData', 'OSMData'], array_column($all, 'type'));
        self::assertSame(0, array_key_first($all));

        $second = array_keys($all)[2];
        self::assertSame($second, $all[array_keys($all)[1]]['next']);

        $resumed = [];

        foreach (PbfReader::blobs($path, $second) as $at => $blob) {
            $resumed[$at] = $blob['data'];
        }

        self::assertSame(array_map(static fn (array $b): string => $b['data'], array_slice($all, 2, null, true)), $resumed);
    }

    public function testDenseAndPlainNodesGiveTheSameTaggedNodes(): void
    {
        $tags = new OsmTags(['addr:housenumber', 'addr:street', 'place', 'name'], ['addr:housenumber', 'place'], []);
        $found = [];

        foreach ([true, false] as $dense) {
            $nodes = [];

            foreach (PbfReader::blobs($this->file($this->small($dense))) as $blob) {
                if ($blob['type'] === 'OSMData') {
                    foreach ($tags->nodes(Block::decode($blob['data'])) as $node) {
                        $nodes[] = $node;
                    }
                }
            }

            $found[] = $nodes;
        }

        self::assertSame($found[0], $found[1]);
        self::assertSame([
            [10, 51500000, -100000, ['addr:housenumber' => '1', 'addr:street' => 'High Street']],
            [12, -33868800, 151209300, ['place' => 'city', 'name' => 'Sydney']],
        ], $found[0]);
    }

    public function testWaysCarryTheirWantedTagsAndNodeReferences(): void
    {
        $tags = new OsmTags(['addr:housenumber'], [], ['addr:housenumber']);
        $ways = [];
        $groups = [];

        foreach (PbfReader::blobs($this->file($this->small())) as $blob) {
            if ($blob['type'] === 'OSMData') {
                $block = Block::decode($blob['data']);
                array_push($groups, ...array_column($block->groups, 0));

                foreach ($tags->ways($block) as $way) {
                    $ways[] = $way;
                }
            }
        }

        // The way without a trigger key is not read; `name` is not wanted. The relation block has a group of field 4.
        self::assertSame([[100, ['addr:housenumber' => '2'], [10, 11, 12, 10]]], $ways);
        self::assertContains(4, $groups);
    }

    public function testNodePositionsAreDecodedOnlyForTheIdsPulled(): void
    {
        $found = [];
        $asked = [];

        foreach (PbfReader::blobs($this->file($this->small())) as $blob) {
            if ($blob['type'] !== 'OSMData') {
                continue;
            }

            $pull = static function (int $first, int $last) use (&$asked): array {
                $asked[] = [$first, $last];

                return array_intersect_key([11 => true, 12 => true], array_flip(range($first, $last)));
            };

            foreach (OsmTags::positions(Block::decode($blob['data']), $pull) as $position) {
                $found[] = $position;
            }
        }

        self::assertSame([[10, 11], [12, 12]], $asked);
        self::assertSame([[11, 51500100, -100100], [12, -33868800, 151209300]], $found);
    }

    public function testTheHeaderMustSayTheFileIsSorted(): void
    {
        PbfReader::checkHeader($this->file($this->small()));
        $this->expectNotToPerformAssertions();
    }

    public function testAnUnsortedFileIsRefused(): void
    {
        $this->expectException(PbfFormatException::class);
        $this->expectExceptionMessage('Sort.Type_then_ID');

        try {
            PbfReader::checkHeader($this->file(new PbfWriter(sorted: false)));
        } catch (PbfFormatException $e) {
            self::assertSame('err_osm_unsorted', $e->key);

            throw $e;
        }
    }

    public function testAnUnsupportedCompressionIsRefused(): void
    {
        $path = $this->file(new PbfWriter(compression: 'lz4'));

        try {
            iterator_to_array(PbfReader::blobs($path));
            self::fail('lz4 must be refused');
        } catch (PbfFormatException $e) {
            self::assertSame('err_osm_compression', $e->key);
        }
    }

    public function testRawBlobsAreRead(): void
    {
        $raw = $this->tempDir() . '/raw.osm.pbf';
        (new PbfWriter(compression: 'raw'))->node(1, 1.0, 1.0, ['place' => 'city'])->write($raw);

        self::assertCount(2, iterator_to_array(PbfReader::blobs($raw)));
    }

    public function testATruncatedFileIsRefused(): void
    {
        $path = $this->file($this->small());
        file_put_contents($path, substr((string) file_get_contents($path), 0, -7));

        try {
            iterator_to_array(PbfReader::blobs($path));
            self::fail('a truncated file must be refused');
        } catch (PbfFormatException $e) {
            self::assertSame('err_osm_truncated', $e->key);
        }
    }

    public function testACorruptBlobIsRefused(): void
    {
        $path = $this->file($this->small());
        $bytes = (string) file_get_contents($path);
        // Damage the zlib stream of the second blob.
        $second = (int) array_keys(iterator_to_array(PbfReader::blobs($path)))[1];
        $bytes[$second + 40] = "\xFF";
        $bytes[$second + 41] = "\xFF";
        file_put_contents($path, $bytes);

        try {
            iterator_to_array(PbfReader::blobs($path));
            self::fail('a corrupt blob must be refused');
        } catch (PbfFormatException $e) {
            self::assertSame('err_osm_truncated', $e->key);
        }
    }

    public function testAFileThatIsNoPbfIsRefused(): void
    {
        $path = $this->tempDir() . '/x.osm.pbf';
        file_put_contents($path, str_repeat('not a pbf file at all. ', 10));

        $this->expectException(PbfFormatException::class);
        iterator_to_array(PbfReader::blobs($path));
    }
}
