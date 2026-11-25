<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Osm;

/**
 * The resume point of the extraction, a small JSON file written atomically (temporary file, then rename) after each unit
 * and after every output was flushed. Each append-only output is cut back to its recorded length on resume.
 */
final class ExtractState
{
    public const VERIFY = 'verify';
    public const SCAN = 'scan';
    public const COMPACT = 'compact';
    public const RESOLVE = 'resolve';
    public const CENTRES = 'centres';
    public const DONE = 'done';

    /** @var list<string> the outputs that only grow */
    public const OUTPUTS = ['addr', 'ways', 'places', 'names'];

    /** Bytes of the PBF file, kept for the progress after it is deleted. */
    public int $size = 0;

    /** Offset of the next blob to read, in the scan and the resolve steps. */
    public int $offset = 0;

    /** Address ways and named streets met so far: the next way slot. */
    public int $ways = 0;

    /** Name number of the next run file. */
    public int $nextRun = 0;

    /** Bucket the centres step is at. */
    public int $bucket = 0;

    /** @var array<string,int> bytes of each output in {@see self::OUTPUTS} */
    public array $lengths = ['addr' => 0, 'ways' => 0, 'places' => 0, 'names' => 0];

    /** @var list<array{id:int,count:int,consumed:int}> sorted run files of pair keys, with the keys the resolve step has taken */
    public array $runs = [];

    /** @var array<int,int> byte offset in `ways` of the first slot of each bucket */
    public array $wayOffsets = [];

    /** @var array<int,int> bytes of each bucket file */
    public array $buckets = [];

    /** @var array<string,array<string,int>> outward code => city => addresses */
    public array $postcity = [];

    /** @var array<string,int> */
    public array $stats = [];

    public function __construct(public string $step = self::VERIFY)
    {
    }

    public function count(string $stat, int $by = 1): void
    {
        $this->stats[$stat] = ($this->stats[$stat] ?? 0) + $by;
    }

    public function save(string $path): void
    {
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode(get_object_vars($this), JSON_THROW_ON_ERROR));
        rename($tmp, $path);
    }

    public static function load(string $path): ?self
    {
        $raw = @file_get_contents($path);
        $data = $raw === false ? null : json_decode($raw, true);

        if (!is_array($data)) {
            return null;
        }

        $state = new self(is_string($data['step'] ?? null) ? $data['step'] : self::VERIFY);
        $int = static fn (mixed $v): int => is_int($v) ? $v : 0;
        $state->size = $int($data['size'] ?? 0);
        $state->offset = $int($data['offset'] ?? 0);
        $state->ways = $int($data['ways'] ?? 0);
        $state->nextRun = $int($data['nextRun'] ?? 0);
        $state->bucket = $int($data['bucket'] ?? 0);

        foreach (self::OUTPUTS as $name) {
            $state->lengths[$name] = $int(is_array($data['lengths'] ?? null) ? ($data['lengths'][$name] ?? 0) : 0);
        }

        foreach (is_array($data['runs'] ?? null) ? $data['runs'] : [] as $run) {
            if (is_array($run)) {
                $state->runs[] = ['id' => $int($run['id'] ?? 0), 'count' => $int($run['count'] ?? 0), 'consumed' => $int($run['consumed'] ?? 0)];
            }
        }

        foreach (is_array($data['wayOffsets'] ?? null) ? $data['wayOffsets'] : [] as $index => $offset) {
            $state->wayOffsets[(int) $index] = $int($offset);
        }

        foreach (is_array($data['buckets'] ?? null) ? $data['buckets'] : [] as $bucket => $length) {
            $state->buckets[(int) $bucket] = $int($length);
        }

        foreach (is_array($data['postcity'] ?? null) ? $data['postcity'] : [] as $outward => $cities) {
            foreach (is_array($cities) ? $cities : [] as $city => $n) {
                $state->postcity[(string) $outward][(string) $city] = $int($n);
            }
        }

        foreach (is_array($data['stats'] ?? null) ? $data['stats'] : [] as $stat => $n) {
            $state->stats[(string) $stat] = $int($n);
        }

        return $state;
    }
}
