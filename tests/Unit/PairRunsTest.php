<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Unit;

use MapCova\OsmAddresses\Osm\PairRuns;
use MapCova\OsmAddresses\Pbf\Bin;
use MapCova\OsmAddresses\Tests\TestCase;

/** The sorted runs of pair keys: taking the keys of the nodes passed, resuming from counts, and merging runs. */
final class PairRunsTest extends TestCase
{
    private static function key(int $node, int $slot): int
    {
        return ($node << PairRuns::SLOT_BITS) | $slot;
    }

    /**
     * @param list<list<int>> $runs keys of each run, in any order
     *
     * @return list<string> the run files
     */
    private function write(array $runs): array
    {
        $dir = $this->tempDir();
        $paths = [];

        foreach ($runs as $i => $keys) {
            sort($keys);
            $paths[] = $dir . '/run-' . $i . '.bin';
            PairRuns::write($paths[$i], $keys);
        }

        return $paths;
    }

    public function testTakesTheKeysOfTheNodesPassedInOrderOfNodeIdAcrossRuns(): void
    {
        $paths = $this->write([
            [self::key(5, 0), self::key(9, 1), self::key(5, 7)],
            [self::key(2, 3), self::key(9, 2), self::key(40, 4)],
            [],
            [self::key(7, 5)],
        ]);
        $runs = new PairRuns($paths, [0, 0, 0, 0]);

        self::assertSame([], $runs->takeUpTo(1));
        self::assertSame([2 => [3]], $runs->takeUpTo(4));
        self::assertEquals([5 => [0, 7], 7 => [5]], array_map(static function (array $slots): array {
            sort($slots);

            return $slots;
        }, $runs->takeUpTo(8)));
        self::assertEquals([9 => [1, 2]], array_map(static function (array $slots): array {
            sort($slots);

            return $slots;
        }, $runs->takeUpTo(30)));
        self::assertSame([40 => [4]], $runs->takeUpTo(1 << 36));
        self::assertSame([], $runs->takeUpTo(1 << 36));
        self::assertSame([3, 3, 0, 1], $runs->consumed());
    }

    public function testResumesFromTheConsumedCounts(): void
    {
        $keys = [];

        for ($node = 1; $node <= 60_000; $node += 3) {
            $keys[] = self::key($node, $node % 1000);
        }

        $paths = $this->write([$keys, [self::key(30_001, 9)]]);
        $first = new PairRuns($paths, [0, 0]);
        $head = $first->takeUpTo(30_000);
        $counts = $first->consumed();

        // Longer than one read buffer, so a resume lands mid-file.
        self::assertGreaterThan(8_000, count($head));
        self::assertSame(array_sum(array_map('count', $head)), array_sum($counts));

        $tail = (new PairRuns($paths, $counts))->takeUpTo(1 << 36);
        $all = (new PairRuns($paths, [0, 0]))->takeUpTo(1 << 36);

        self::assertSame(count($all), count($head) + count($tail));
        self::assertSame($all, $head + $tail);
    }

    public function testMergeWritesAllKeysSorted(): void
    {
        $runs = [];
        $all = [];

        // More runs than the extraction ever keeps (200), with more keys than one read buffer in some.
        for ($i = 0; $i < 230; ++$i) {
            $keys = [];

            for ($j = 0; $j < ($i === 7 ? 20_000 : 5); ++$j) {
                $keys[] = self::key(($i * 7919 + $j * 104_729) % 1_000_003, $j);
            }

            $runs[] = $keys;
            array_push($all, ...$keys);
        }

        $paths = $this->write($runs);
        $out = $this->tempDir() . '/merged.bin';

        self::assertSame(count($all), PairRuns::merge($paths, $out));
        sort($all);
        self::assertSame($all, Bin::allU64((string) file_get_contents($out)));
        self::assertFileDoesNotExist($out . '.tmp');
    }

    public function testAMissingRunFileIsRefused(): void
    {
        $this->expectExceptionMessage('working files');
        new PairRuns([$this->tempDir() . '/nothing.bin'], [0]);
    }
}
