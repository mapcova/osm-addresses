<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Support;

/**
 * A minimal OSM PBF writer for tests: nodes (dense or plain), ways and relations, each kind in blocks of a chosen size
 * (a small size gives many blobs to resume between), sorted by id, zlib, raw or unsupported compression, and a header
 * that says whether the file is sorted.
 */
final class PbfWriter
{
    /** @var array<int,array{int,int,array<string,string>}> id => [lat, lon in 1e-7 degree, tags] */
    private array $nodes = [];
    /** @var array<int,array{list<int>,array<string,string>}> */
    private array $ways = [];
    /** @var list<array{int,array<string,string>,list<array{string,int,int}>}> id, tags, members (role, type, id) */
    private array $relations = [];

    /**
     * @param int    $nodesPerBlock nodes in a block (and in a blob)
     * @param int    $waysPerBlock  ways in a block
     * @param string $compression   `zlib`, `raw` or `lz4` (declared only: the data are not read)
     */
    public function __construct(
        private readonly int $nodesPerBlock = 8000,
        private readonly int $waysPerBlock = 8000,
        private readonly bool $dense = true,
        private readonly bool $sorted = true,
        private readonly string $compression = 'zlib',
    ) {
    }

    /** @param array<string,string> $tags */
    public function node(int $id, float $lat, float $lon, array $tags = []): self
    {
        $this->nodes[$id] = [(int) round($lat * 1e7), (int) round($lon * 1e7), $tags];

        return $this;
    }

    /**
     * @param list<int> $refs
     * @param array<string,string> $tags
     */
    public function way(int $id, array $refs, array $tags): self
    {
        $this->ways[$id] = [$refs, $tags];

        return $this;
    }

    /**
     * @param array<string,string> $tags
     * @param list<array{string,int,int}> $members role, type (0 node, 1 way), id
     */
    public function relation(int $id, array $tags, array $members): self
    {
        $this->relations[] = [$id, $tags, $members];

        return $this;
    }

    private static function varint(int $v): string
    {
        $o = '';
        while ($v >= 0x80) {
            $o .= \chr(($v & 0x7f) | 0x80);
            $v >>= 7;
        }

        return $o . \chr($v);
    }

    private static function zig(int $v): int
    {
        return ($v << 1) ^ ($v >> 63);
    }

    private static function field(int $no, int $wt, string $payload): string
    {
        return self::varint(($no << 3) | $wt) . $payload;
    }

    private static function bytes(int $no, string $b): string
    {
        return self::field($no, 2, self::varint(\strlen($b)) . $b);
    }

    private static function int(int $no, int $v): string
    {
        return self::field($no, 0, self::varint($v));
    }

    /** @param list<int> $vals */
    private static function packed(int $no, array $vals, bool $zigzag = false): string
    {
        $b = '';
        foreach ($vals as $v) {
            $b .= self::varint($zigzag ? self::zig($v) : $v);
        }

        return self::bytes($no, $b);
    }

    /**
     * @param list<int> $vals
     * @return list<int> deltas
     */
    private static function delta(array $vals): array
    {
        $out = [];
        $prev = 0;
        foreach ($vals as $v) {
            $out[] = $v - $prev;
            $prev = $v;
        }

        return $out;
    }

    private function blob(string $type, string $content): string
    {
        $data = match ($this->compression) {
            'raw' => self::bytes(1, $content),
            'lz4' => self::bytes(6, $content),
            default => self::bytes(3, (string) gzcompress($content)),
        };
        $blob = self::int(2, \strlen($content)) . $data;
        $header = self::bytes(1, $type) . self::int(3, \strlen($blob));

        return pack('N', \strlen($header)) . $header . $blob;
    }

    public function write(string $path, ?int $timestamp = 1700000000): void
    {
        file_put_contents($path, $this->contents($timestamp));
    }

    public function contents(?int $timestamp = 1700000000): string
    {
        $header = ($this->sorted ? self::bytes(5, 'Sort.Type_then_ID') : '') . ($timestamp !== null ? self::int(32, $timestamp) : '');
        $out = $this->blob('OSMHeader', $header);

        ksort($this->nodes);
        foreach (array_chunk($this->nodes, max(1, $this->nodesPerBlock), true) as $chunk) {
            $table = new StringTable();
            $group = $this->dense ? $this->denseGroup($chunk, $table) : $this->plainGroup($chunk, $table);
            $out .= $this->blob('OSMData', $this->block($table, $group));
        }

        ksort($this->ways);
        foreach (array_chunk($this->ways, max(1, $this->waysPerBlock), true) as $chunk) {
            $table = new StringTable();
            $ways = '';
            foreach ($chunk as $id => [$refs, $tags]) {
                [$k, $v] = $table->tags($tags);
                $ways .= self::bytes(3, self::int(1, $id) . self::packed(2, $k) . self::packed(3, $v) . self::packed(8, self::delta($refs), true));
            }
            $out .= $this->blob('OSMData', $this->block($table, self::bytes(2, $ways)));
        }

        if ($this->relations !== []) {
            $table = new StringTable();
            $rels = '';
            foreach ($this->relations as [$id, $tags, $members]) {
                [$k, $v] = $table->tags($tags);
                $roles = [];
                $mem = [];
                $types = [];
                foreach ($members as [$role, $type, $mid]) {
                    $roles[] = $table->index($role);
                    $mem[] = $mid;
                    $types[] = $type;
                }
                $rels .= self::bytes(4, self::int(1, $id) . self::packed(2, $k) . self::packed(3, $v) . self::packed(8, $roles) . self::packed(9, self::delta($mem), true) . self::packed(10, $types));
            }
            $out .= $this->blob('OSMData', $this->block($table, self::bytes(2, $rels)));
        }

        return $out;
    }

    /** @param array<int,array{int,int,array<string,string>}> $nodes */
    private function denseGroup(array $nodes, StringTable $table): string
    {
        $ids = [];
        $lats = [];
        $lons = [];
        $kv = [];
        $tagged = false;
        foreach ($nodes as $id => [$la, $lo, $tags]) {
            $ids[] = $id;
            $lats[] = $la;
            $lons[] = $lo;
            foreach ($tags as $k => $v) {
                $kv[] = $table->index((string) $k);
                $kv[] = $table->index($v);
                $tagged = true;
            }
            $kv[] = 0;
        }

        // A block without any tag has no `keys_vals` at all, as in real files.
        return self::bytes(2, self::bytes(2, self::packed(1, self::delta($ids), true)
            . self::packed(8, self::delta($lats), true)
            . self::packed(9, self::delta($lons), true)
            . ($tagged ? self::packed(10, $kv) : '')));
    }

    /** @param array<int,array{int,int,array<string,string>}> $nodes */
    private function plainGroup(array $nodes, StringTable $table): string
    {
        $out = '';
        foreach ($nodes as $id => [$la, $lo, $tags]) {
            [$k, $v] = $table->tags($tags);
            $out .= self::bytes(1, self::int(1, self::zig($id)) . ($k === [] ? '' : self::packed(2, $k) . self::packed(3, $v)) . self::int(8, self::zig($la)) . self::int(9, self::zig($lo)));
        }

        return self::bytes(2, $out);
    }

    private function block(StringTable $table, string $groups): string
    {
        $st = '';
        foreach ($table->all() as $s) {
            $st .= self::bytes(1, $s);
        }

        // granularity 100 nanodegrees: the stored value is in 1e-7 degree
        return self::bytes(1, $st) . $groups . self::int(17, 100);
    }
}
