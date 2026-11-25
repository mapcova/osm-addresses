<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Osm;

use MapCova\OsmAddresses\Build\PermanentBuildException;
use MapCova\OsmAddresses\Pbf\Bin;
use MapCova\OsmAddresses\Pbf\Block;
use MapCova\OsmAddresses\Pbf\PbfFormatException;
use MapCova\OsmAddresses\Pbf\PbfReader;

/**
 * Turns an OpenStreetMap PBF extract (sorted: nodes, then ways, then relations) into the TSV of its addresses, in units
 * that each end with a checkpoint, with a memory that does not grow with the extract.
 *
 * - verify: the file's checksum (when the publisher gives one) and header.
 * - scan: every blob once. Address nodes and place nodes are written at once. An address way, or a street with its
 *   name in another language, is written without position to `ways`, in the order of its slot, and up to four of its
 *   nodes become pair keys (`node id << 27 | slot`), kept in memory and written as a sorted run at the end of each unit.
 * - compact: when the scan left more runs than a resolve step should read, the smallest are merged.
 * - resolve: the node blobs once more. The runs hand out the pair keys of the nodes the blob passes, and the position
 *   of each goes to the bucket file of its slot (`slot >> bucketBits`). Then the PBF and the runs are deleted.
 * - centres: one unit for each bucket of slots: the mean of the positions found is the way's centre, and its line is
 *   written to `addr` (or `names`). A way none of whose nodes was found is dropped. A last unit writes `postcity`.
 *
 * Every unit is idempotent: the outputs only grow and are cut back to the length the checkpoint recorded, so a unit a
 * kill interrupted is simply done again. Files are named after the chunk's path (`<chunk>.addr.tsv`…), so that the
 * builder's cleaning of the build folder removes them.
 *
 * The costs of a unit are estimated seconds, used by the budget of a sliced build (the command line has none).
 */
final class OsmExtractor
{
    private const SCAN_COST_PER_BLOB = 0.03;
    private const RESOLVE_COST_PER_BLOB = 0.015;
    private const MERGE_COST = 20.0;
    private const BUCKET_COST = 2.0;
    private const MD5_BYTES_PER_SECOND = 150_000_000;
    private const RUN_KEYS = 1_000_000;

    /** Nodes of a way that stand for its centre: its first, and those a quarter, a half and three quarters round its ring. */
    private const SAMPLES = 4;

    private ExtractState $state;

    private readonly OsmTags $tags;

    /** @var array<string,AppendFile> */
    private array $files = [];

    /** @var array<int,AppendFile> */
    private array $bucketFiles = [];

    /** @var list<int> pair keys of the unit, not yet written as a run */
    private array $keys = [];

    /** @var list<string> files to delete once the unit is checkpointed */
    private array $discard = [];

    /** @var list<string> */
    private readonly array $languages;

    /**
     * @param string       $pbf           the extract
     * @param list<string> $languages     codes of the languages whose `name:<code>` forms are kept (`ga`, `cy`)
     * @param int          $blobsPerUnit  blobs a scan or resolve unit reads
     * @param int          $bucketBits    a bucket holds 2^bucketBits way slots
     * @param int          $maxRuns       the scan's runs are merged down to this many before the resolve step
     * @param int          $mergeFan      runs one compaction unit merges
     */
    public function __construct(
        private readonly string $pbf,
        array $languages = [],
        private readonly int $blobsPerUnit = 256,
        private readonly int $bucketBits = 18,
        private readonly int $maxRuns = 200,
        private readonly int $mergeFan = 32,
    ) {
        $this->languages = array_values(array_filter($languages, static fn (string $code): bool => preg_match('/^[a-z_]+$/', $code) === 1));
        $this->state = ExtractState::load(self::path($pbf, 'osm.json')) ?? new ExtractState();
        $this->tags = new OsmTags(
            [...AddressTags::KEYS, 'place', 'name', 'highway', ...array_map(static fn (string $code): string => 'name:' . $code, $this->languages)],
            ['addr:housenumber', 'addr:housename', 'place'],
            ['addr:housenumber', 'addr:housename', ...array_map(static fn (string $code): string => 'name:' . $code, $this->languages)],
        );
    }

    public static function path(string $base, string $name): string
    {
        return $base . '.' . $name;
    }

    /** The extracted addresses (see {@see AddressTags::HEADER}). */
    public static function addressesPath(string $chunkPath): string
    {
        return self::path($chunkPath, 'addr.tsv');
    }

    /** The place nodes: `place`, `name`, `lat_e6`, `lon_e6`, then a column for each language (read by {@see PlaceIndex}). */
    public static function placesPath(string $chunkPath): string
    {
        return self::path($chunkPath, 'places.tsv');
    }

    /** The streets with a name in another language: `name`, `lat_e6`, `lon_e6` (of their first node), then a column for each language. */
    public static function namesPath(string $chunkPath): string
    {
        return self::path($chunkPath, 'names.tsv');
    }

    /** The most frequent city of each outward code of a British postcode: `outward`, `city`. */
    public static function postcityPath(string $chunkPath): string
    {
        return self::path($chunkPath, 'postcity.tsv');
    }

    public function step(): string
    {
        return $this->state->step;
    }

    /** Whether the extract itself is still read (it is deleted when the resolve step ends). */
    public function needsPbf(): bool
    {
        return in_array($this->state->step, [ExtractState::VERIFY, ExtractState::SCAN, ExtractState::COMPACT, ExtractState::RESOLVE], true);
    }

    public function needsChecksum(): bool
    {
        return $this->state->step === ExtractState::VERIFY;
    }

    /** @return array<string,int> what was counted so far: objects kept, and dropped by reason */
    public function stats(): array
    {
        return $this->state->stats;
    }

    /** How far the extraction is, from 0 to 1. */
    public function progress(): float
    {
        $size = max(1, $this->state->size);
        $buckets = max(1, $this->bucketCount());

        return match ($this->state->step) {
            ExtractState::VERIFY => 0.0,
            ExtractState::SCAN => 0.4 * min(1, $this->state->offset / $size),
            ExtractState::COMPACT => 0.4,
            ExtractState::RESOLVE => 0.4 + 0.3 * min(1, $this->state->offset / $size),
            ExtractState::CENTRES => 0.7 + 0.3 * min(1, $this->state->bucket / $buckets),
            default => 1.0,
        };
    }

    /**
     * Run units while `$take` accepts them.
     *
     * @param callable(float):bool $take     asks to run a unit costing this many seconds; `false` stops
     * @param callable():void      $unit     called after each unit's checkpoint
     * @param string|null          $checksum the md5 the publisher gives for the file, to check in the first unit
     *
     * @return bool whether the extraction is complete
     *
     * @throws PermanentBuildException for a file that cannot be used
     */
    public function run(callable $take, callable $unit, ?string $checksum = null): bool
    {
        try {
            // What a unit killed before its checkpoint wrote past the recorded lengths is cut off, in every output.
            if ($this->state->step !== ExtractState::VERIFY && $this->state->step !== ExtractState::DONE) {
                foreach (ExtractState::OUTPUTS as $name) {
                    $this->file($name);
                }
            }

            while ($this->state->step !== ExtractState::DONE) {
                if (!$take($this->cost())) {
                    return false;
                }

                match ($this->state->step) {
                    ExtractState::VERIFY => $this->verify($checksum),
                    ExtractState::SCAN => $this->scan(),
                    ExtractState::COMPACT => $this->compact(),
                    ExtractState::RESOLVE => $this->resolve(),
                    default => $this->centres(),
                };
                $this->commit();
                $unit();
            }
        } catch (PbfFormatException $e) {
            throw new PermanentBuildException($e->key);
        }

        return true;
    }

    private function cost(): float
    {
        return match ($this->state->step) {
            ExtractState::VERIFY => max(1.0, (int) @filesize($this->pbf) / self::MD5_BYTES_PER_SECOND),
            ExtractState::SCAN => $this->blobsPerUnit * self::SCAN_COST_PER_BLOB,
            ExtractState::COMPACT => self::MERGE_COST,
            ExtractState::RESOLVE => $this->blobsPerUnit * self::RESOLVE_COST_PER_BLOB,
            default => self::BUCKET_COST,
        };
    }

    // --- verify -------------------------------------------------------------------------------------------

    private function verify(?string $checksum): void
    {
        $size = @filesize($this->pbf);

        if ($size === false) {
            throw new PermanentBuildException('err_osm_state');
        }

        PbfReader::checkHeader($this->pbf);

        if ($checksum !== null && hash_file('md5', $this->pbf) !== strtolower($checksum)) {
            throw new PermanentBuildException('err_osm_md5');
        }

        $languages = implode("\t", $this->languages);
        $headers = [
            'addr' => implode("\t", AddressTags::HEADER) . "\n",
            'ways' => '',
            'places' => rtrim("place\tname\tlat_e6\tlon_e6\t" . $languages, "\t") . "\n",
            'names' => rtrim("name\tlat_e6\tlon_e6\t" . $languages, "\t") . "\n",
        ];

        foreach ($headers as $name => $header) {
            file_put_contents(self::path($this->pbf, $name . '.tsv'), $header);
            $this->state->lengths[$name] = strlen($header);
        }

        $this->state->size = $size;
        $this->state->step = ExtractState::SCAN;
        $this->state->offset = 0;
    }

    // --- scan ---------------------------------------------------------------------------------------------

    private function scan(): void
    {
        $blobs = 0;
        $finished = true;

        foreach (PbfReader::blobs($this->pbf, $this->state->offset) as $blob) {
            if ($blobs >= $this->blobsPerUnit) {
                $finished = false;
                break;
            }

            ++$blobs;
            $relations = false;

            if ($blob['type'] === 'OSMData') {
                $block = Block::decode($blob['data']);
                $nodes = false;
                $ways = false;

                foreach ($block->groups as [$field]) {
                    $nodes = $nodes || OsmTags::isNodes($field);
                    $ways = $ways || $field === 3;
                    $relations = $relations || $field === 4;
                }

                if ($nodes) {
                    $this->scanNodes($block);
                }

                if ($ways) {
                    $this->scanWays($block);
                }
            }

            $this->state->offset = $blob['next'];

            // The relations (0.06 % of the addresses) are not read, and nothing after them is needed.
            if ($relations) {
                break;
            }
        }

        $this->flushRun();

        if ($finished) {
            $this->state->offset = 0;
            $this->state->step = count($this->state->runs) > $this->maxRuns ? ExtractState::COMPACT : ExtractState::RESOLVE;
        }
    }

    private function scanNodes(Block $block): void
    {
        foreach ($this->tags->nodes($block) as [$id, $lat, $lon, $tags]) {
            if (isset($tags['addr:housenumber']) || isset($tags['addr:housename'])) {
                $address = AddressTags::read($tags);

                if (is_string($address)) {
                    $this->state->count($address);
                } else {
                    $this->state->count('addr_nodes');
                    $this->address('n' . $id, $lat, $lon, $address['numbers'], $address['rest']);
                }
            }

            $place = $tags['place'] ?? '';
            $name = AddressTags::clean($tags['name'] ?? '');

            if (isset(PlaceIndex::RADIUS[$place]) && $name !== '') {
                $this->state->count('places');
                $this->file('places')->write(implode("\t", [$place, $name, $lat, $lon, ...$this->forms($tags, $name)]) . "\n");
            }
        }
    }

    private function scanWays(Block $block): void
    {
        foreach ($this->tags->ways($block) as [$id, $tags, $refs]) {
            if (isset($tags['addr:housenumber']) || isset($tags['addr:housename'])) {
                $address = AddressTags::read($tags);

                if (is_string($address)) {
                    $this->state->count($address);
                } elseif ($refs === []) {
                    $this->state->count('no_centre');
                } else {
                    $this->state->count('addr_ways');
                    $this->slot(implode("\t", ['a', $id, implode(';', $address['numbers']), ...$address['rest']]), $this->sample($refs));
                }

                continue;
            }

            $name = AddressTags::clean($tags['name'] ?? '');
            $forms = $this->forms($tags, $name);

            if (isset($tags['highway']) && $name !== '' && $refs !== [] && implode('', $forms) !== '') {
                $this->state->count('named_streets');
                $this->slot(implode("\t", ['h', $name, ...$forms]), [$refs[0]]);
            }
        }
    }

    /**
     * The nodes that stand for a way's centre: its ring without the closing node, sampled at a quarter steps.
     *
     * @param list<int> $refs
     *
     * @return list<int>
     */
    private function sample(array $refs): array
    {
        $n = count($refs);

        if ($n > 1 && $refs[0] === $refs[$n - 1]) {
            --$n;
        }

        $picked = [];

        foreach ([0, intdiv($n, self::SAMPLES), intdiv($n, 2), intdiv(3 * $n, self::SAMPLES)] as $index) {
            $picked[$refs[$index]] = true;
        }

        return array_keys($picked);
    }

    /**
     * The next way slot: its line, and the pair keys of the nodes that give its position.
     *
     * @param list<int> $nodes
     */
    private function slot(string $line, array $nodes): void
    {
        $slot = $this->state->ways++;

        if ($slot >= PairRuns::MAX_SLOT) {
            throw new PermanentBuildException('err_osm_id_range');
        }

        $ways = $this->file('ways');

        if (($slot & ((1 << $this->bucketBits) - 1)) === 0) {
            $this->state->wayOffsets[$slot >> $this->bucketBits] = $ways->length();
        }

        $ways->write($line . "\n");

        foreach ($nodes as $node) {
            if ($node < 0 || $node >= PairRuns::MAX_NODE) {
                throw new PermanentBuildException('err_osm_id_range');
            }

            $this->keys[] = ($node << PairRuns::SLOT_BITS) | $slot;
        }

        if (count($this->keys) >= self::RUN_KEYS) {
            $this->flushRun();
        }
    }

    private function flushRun(): void
    {
        if ($this->keys === []) {
            return;
        }

        sort($this->keys);
        $id = $this->state->nextRun++;
        PairRuns::write(self::path($this->pbf, 'run-' . $id . '.bin'), $this->keys);
        $this->state->runs[] = ['id' => $id, 'count' => count($this->keys), 'consumed' => 0];
        $this->keys = [];
    }

    /**
     * @param list<string> $numbers
     * @param list<string> $rest    the columns after the number
     */
    private function address(string $id, int $lat, int $lon, array $numbers, array $rest): void
    {
        $file = $this->file('addr');
        $outward = AddressTags::outward($rest[3]);

        foreach ($numbers as $number) {
            $file->write(implode("\t", [$id, $lat, $lon, $number, ...$rest]) . "\n");
        }

        if ($outward !== null && $rest[4] !== '') {
            $this->state->postcity[$outward][$rest[4]] = ($this->state->postcity[$outward][$rest[4]] ?? 0) + count($numbers);
        }
    }

    /**
     * The name of an object in each language, empty where it is the object's own name or missing.
     *
     * @param array<string,string> $tags
     *
     * @return list<string>
     */
    private function forms(array $tags, string $name): array
    {
        $forms = [];

        foreach ($this->languages as $code) {
            $form = AddressTags::clean($tags['name:' . $code] ?? '');
            $forms[] = $form === $name ? '' : $form;
        }

        return $forms;
    }

    // --- compact ------------------------------------------------------------------------------------------

    private function compact(): void
    {
        $order = array_keys($this->state->runs);
        usort($order, fn (int $a, int $b): int => [$this->state->runs[$a]['count'], $this->state->runs[$a]['id']] <=> [$this->state->runs[$b]['count'], $this->state->runs[$b]['id']]);
        $merge = array_slice($order, 0, max(2, $this->mergeFan));
        $paths = [];
        $count = 0;

        foreach ($merge as $index) {
            $paths[] = self::path($this->pbf, 'run-' . $this->state->runs[$index]['id'] . '.bin');
            $count += $this->state->runs[$index]['count'];
        }

        $id = $this->state->nextRun++;
        PairRuns::merge($paths, self::path($this->pbf, 'run-' . $id . '.bin'));

        $kept = [];

        foreach ($this->state->runs as $index => $run) {
            if (!in_array($index, $merge, true)) {
                $kept[] = $run;
            }
        }

        $kept[] = ['id' => $id, 'count' => $count, 'consumed' => 0];
        $this->state->runs = $kept;
        $this->discard = [...$this->discard, ...$paths];

        if (count($kept) <= $this->maxRuns) {
            $this->state->step = ExtractState::RESOLVE;
        }
    }

    // --- resolve ------------------------------------------------------------------------------------------

    private function resolve(): void
    {
        $paths = [];
        $consumed = [];

        foreach ($this->state->runs as $run) {
            $paths[] = self::path($this->pbf, 'run-' . $run['id'] . '.bin');
            $consumed[] = $run['consumed'];
        }

        $runs = new PairRuns($paths, $consumed);
        $lastNode = -1;
        $slots = [];
        $pull = function (int $first, int $last) use ($runs, &$lastNode, &$slots): array {
            if ($first <= $lastNode) {
                throw new PermanentBuildException('err_osm_unsorted');
            }

            $lastNode = $last;

            return $slots = $runs->takeUpTo($last);
        };
        $blobs = 0;
        $finished = true;

        foreach (PbfReader::blobs($this->pbf, $this->state->offset) as $blob) {
            if ($blobs >= $this->blobsPerUnit) {
                $finished = false;
                break;
            }

            ++$blobs;

            if ($blob['type'] === 'OSMData') {
                $block = Block::decode($blob['data']);
                $past = false;

                foreach ($block->groups as [$field]) {
                    $past = $past || $field >= 3;
                }

                foreach (OsmTags::positions($block, $pull) as [$id, $lat, $lon]) {
                    foreach ($slots[$id] as $slot) {
                        $this->bucketFile($slot >> $this->bucketBits)->write(pack('VVV', $slot, $lat & 0xFFFFFFFF, $lon & 0xFFFFFFFF));
                    }
                }

                // The ways begin: every node was met.
                if ($past) {
                    break;
                }
            }

            $this->state->offset = $blob['next'];
        }

        $progress = [];

        foreach ($this->state->runs as $index => $run) {
            $progress[] = ['id' => $run['id'], 'count' => $run['count'], 'consumed' => $runs->consumed()[$index] ?? $run['consumed']];
        }

        $this->state->runs = $progress;

        if ($finished) {
            $this->discard[] = $this->pbf;

            foreach ($this->state->runs as $run) {
                $this->discard[] = self::path($this->pbf, 'run-' . $run['id'] . '.bin');
            }

            $this->state->runs = [];
            $this->state->step = ExtractState::CENTRES;
            $this->state->bucket = 0;
        }
    }

    // --- centres ------------------------------------------------------------------------------------------

    private function bucketCount(): int
    {
        return $this->state->ways === 0 ? 0 : (($this->state->ways - 1) >> $this->bucketBits) + 1;
    }

    private function centres(): void
    {
        // The extract goes with the resolve step; this repeats the deletion for a kill between the checkpoint and it.
        @unlink($this->pbf);

        if ($this->state->bucket < $this->bucketCount()) {
            $this->centresOfBucket($this->state->bucket);
            ++$this->state->bucket;

            return;
        }

        $this->writePostcity();
        $this->discard[] = self::path($this->pbf, 'ways.tsv');
        $this->state->step = ExtractState::DONE;
    }

    private function centresOfBucket(int $bucket): void
    {
        $first = $bucket << $this->bucketBits;
        $slots = min(1 << $this->bucketBits, $this->state->ways - $first);
        $lat = array_fill(0, $slots, 0);
        $lon = array_fill(0, $slots, 0);
        $found = array_fill(0, $slots, 0);
        $bucketPath = self::path($this->pbf, 'bucket-' . $bucket . '.bin');
        $left = $this->state->buckets[$bucket] ?? 0;

        if ($left > 0) {
            $handle = @fopen($bucketPath, 'rb');

            if ($handle === false) {
                throw new PermanentBuildException('err_osm_state');
            }

            while ($left > 0) {
                $raw = fread($handle, min($left, 12 * 65_536));

                if ($raw === false || $raw === '') {
                    throw new PermanentBuildException('err_osm_state');
                }

                $left -= strlen($raw);
                $values = Bin::allU32($raw);

                for ($i = 0, $n = count($values) - 2; $i < $n; $i += 3) {
                    $at = $values[$i] - $first;

                    if ($at < 0 || $at >= $slots) {
                        throw new PermanentBuildException('err_osm_state');
                    }

                    $lat[$at] += $values[$i + 1] >= 0x80000000 ? $values[$i + 1] - 0x100000000 : $values[$i + 1];
                    $lon[$at] += $values[$i + 2] >= 0x80000000 ? $values[$i + 2] - 0x100000000 : $values[$i + 2];
                    ++$found[$at];
                }
            }

            fclose($handle);
        }

        $handle = @fopen(self::path($this->pbf, 'ways.tsv'), 'rb');

        if ($handle === false) {
            throw new PermanentBuildException('err_osm_state');
        }

        fseek($handle, $this->state->wayOffsets[$bucket] ?? 0);

        for ($i = 0; $i < $slots; ++$i) {
            $line = fgets($handle);

            if ($line === false) {
                throw new PermanentBuildException('err_osm_state');
            }

            if ($found[$i] === 0) {
                $this->state->count('no_centre');
                continue;
            }

            $centreLat = (int) round($lat[$i] / $found[$i]);
            $centreLon = (int) round($lon[$i] / $found[$i]);
            $parts = explode("\t", rtrim($line, "\r\n"));

            if ($parts[0] === 'a') {
                $this->address('w' . $parts[1], $centreLat, $centreLon, explode(';', $parts[2]), array_slice($parts, 3));
            } else {
                $this->file('names')->write(implode("\t", [$parts[1], $centreLat, $centreLon, ...array_slice($parts, 2)]) . "\n");
            }
        }

        fclose($handle);
        $this->discard[] = $bucketPath;
    }

    private function writePostcity(): void
    {
        ksort($this->state->postcity);
        $out = "outward\tcity\n";

        foreach ($this->state->postcity as $outward => $cities) {
            uksort($cities, static fn (string $a, string $b): int => [$cities[$b], $a] <=> [$cities[$a], $b]);
            $out .= $outward . "\t" . array_key_first($cities) . "\n";
        }

        file_put_contents(self::postcityPath($this->pbf), $out);
    }

    // --- files and checkpoint -----------------------------------------------------------------------------

    private function file(string $name): AppendFile
    {
        return $this->files[$name] ??= new AppendFile(self::path($this->pbf, $name . '.tsv'), $this->state->lengths[$name]);
    }

    private function bucketFile(int $bucket): AppendFile
    {
        return $this->bucketFiles[$bucket] ??= new AppendFile(self::path($this->pbf, 'bucket-' . $bucket . '.bin'), $this->state->buckets[$bucket] ?? 0);
    }

    /** Flush the outputs, record their lengths, save the state, then delete what the unit retired. */
    private function commit(): void
    {
        foreach ($this->files as $name => $file) {
            $file->flush();
            $this->state->lengths[$name] = $file->length();
        }

        foreach ($this->bucketFiles as $bucket => $file) {
            $file->flush();
            $this->state->buckets[$bucket] = $file->length();
        }

        $this->state->save(self::path($this->pbf, 'osm.json'));
        $this->files = [];
        $this->bucketFiles = [];

        foreach ($this->discard as $path) {
            @unlink($path);
        }

        $this->discard = [];
    }
}
