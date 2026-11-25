<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Osm;

use MapCova\OsmAddresses\Build\PermanentBuildException;
use MapCova\OsmAddresses\Pbf\Bin;
use SplMinHeap;

/**
 * Pair keys of the extraction: `node id << 27 | way slot`, 63 bits, so that sorting the keys sorts them by node id.
 * The keys are written in sorted runs (u64 little-endian files); the resolve step reads the nodes of the extract in id
 * order and pulls the keys of the nodes it has passed from every run, and a run's progress is a count of keys that
 * a checkpoint can store and a resume seek to.
 */
final class PairRuns
{
    public const SLOT_BITS = 27;
    public const MAX_NODE = 1 << 36;
    public const MAX_SLOT = 1 << self::SLOT_BITS;

    private const READ_KEYS = 8_192;

    /** @var array<int,resource|null> */
    private array $handles = [];

    /** @var array<int,list<int>> keys read from each run and not taken yet */
    private array $buffers = [];

    /** @var array<int,int> */
    private array $positions = [];

    /** @var array<int,bool> */
    private array $exhausted = [];

    /** @var array<int,int> keys taken from each run */
    private array $consumed = [];

    /**
     * @param list<string> $paths    the run files
     * @param array<int,int> $consumed keys already taken from each, to carry on from
     */
    public function __construct(private readonly array $paths, array $consumed)
    {
        foreach ($paths as $index => $path) {
            $handle = @fopen($path, 'rb');

            if ($handle === false) {
                throw new PermanentBuildException('err_osm_state');
            }

            fseek($handle, ($consumed[$index] ?? 0) * 8);
            $this->handles[$index] = $handle;
            $this->buffers[$index] = [];
            $this->positions[$index] = 0;
            $this->exhausted[$index] = false;
            $this->consumed[$index] = $consumed[$index] ?? 0;
        }
    }

    public function __destruct()
    {
        foreach ($this->handles as $handle) {
            if ($handle !== null) {
                fclose($handle);
            }
        }
    }

    /** @param list<int> $keys sorted */
    public static function write(string $path, array $keys): void
    {
        $tmp = $path . '.tmp';
        file_put_contents($tmp, Bin::packAll('P', $keys));
        rename($tmp, $path);
    }

    /**
     * Every key whose node id is at most `$lastNode`, taken from all runs, grouped by node id.
     *
     * @return array<int,list<int>> node id => way slots
     */
    public function takeUpTo(int $lastNode): array
    {
        $taken = [];

        foreach (array_keys($this->handles) as $index) {
            while (true) {
                if ($this->positions[$index] >= count($this->buffers[$index])) {
                    if ($this->exhausted[$index]) {
                        break;
                    }

                    $this->refill($index);

                    if ($this->exhausted[$index]) {
                        break;
                    }
                }

                $buffer = $this->buffers[$index];
                $position = $this->positions[$index];
                $count = count($buffer);

                while ($position < $count && ($buffer[$position] >> self::SLOT_BITS) <= $lastNode) {
                    $key = $buffer[$position++];
                    $taken[$key >> self::SLOT_BITS][] = $key & (self::MAX_SLOT - 1);
                    ++$this->consumed[$index];
                }

                $this->positions[$index] = $position;

                // Stopped on a key past the node: this run has nothing more for it.
                if ($position < $count) {
                    break;
                }
            }
        }

        return $taken;
    }

    /** @return array<int,int> keys taken from each run so far */
    public function consumed(): array
    {
        return $this->consumed;
    }

    /**
     * Merge sorted runs into one file (the compaction of a scan that left more runs than the resolve step can read at once).
     *
     * @param list<string> $paths
     *
     * @return int keys written
     */
    public static function merge(array $paths, string $out): int
    {
        $runs = new self($paths, array_fill(0, count($paths), 0));
        $heap = new class () extends SplMinHeap {
            protected function compare(mixed $value1, mixed $value2): int
            {
                return $value2[0] <=> $value1[0];
            }
        };

        foreach (array_keys($paths) as $index) {
            $runs->push($heap, $index);
        }

        $tmp = $out . '.tmp';
        $file = fopen($tmp, 'wb');

        if ($file === false) {
            throw new PermanentBuildException('err_osm_state');
        }

        $written = 0;
        $pending = [];

        while (!$heap->isEmpty()) {
            [$key, $index] = $heap->extract();
            $pending[] = $key;
            ++$written;

            if (count($pending) >= self::READ_KEYS) {
                fwrite($file, Bin::packAll('P', $pending));
                $pending = [];
            }

            $runs->push($heap, $index);
        }

        fwrite($file, Bin::packAll('P', $pending));
        fclose($file);
        rename($tmp, $out);

        return $written;
    }

    /** The next key of a run onto a merge heap, if it has one. */
    /** @param SplMinHeap<array{int,int}> $heap */
    private function push(SplMinHeap $heap, int $index): void
    {
        if ($this->positions[$index] >= count($this->buffers[$index])) {
            $this->refill($index);
        }

        if (!$this->exhausted[$index]) {
            $heap->insert([$this->buffers[$index][$this->positions[$index]++], $index]);
        }
    }

    private function refill(int $index): void
    {
        $handle = $this->handles[$index];
        $raw = $handle === null ? false : fread($handle, self::READ_KEYS * 8);

        if ($raw === false || $raw === '') {
            $this->exhausted[$index] = true;
            $this->buffers[$index] = [];
            $this->positions[$index] = 0;

            return;
        }

        $this->buffers[$index] = Bin::allU64(substr($raw, 0, intdiv(strlen($raw), 8) * 8));
        $this->positions[$index] = 0;
    }
}
