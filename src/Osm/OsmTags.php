<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Osm;

use Generator;
use MapCova\OsmAddresses\Pbf\Block;
use MapCova\OsmAddresses\Pbf\Wire;

/**
 * Reads the objects of a decoded block that matter to the extraction. A block is skipped without decoding when its string
 * table has none of the trigger keys; in a block that has some, only the objects that carry one are decoded, and only
 * the wanted tags are kept.
 */
final class OsmTags
{
    /** @var array<string,true> */
    private readonly array $wanted;

    /** @var array<string,true> */
    private readonly array $nodeTriggers;

    /** @var array<string,true> */
    private readonly array $wayTriggers;

    /**
     * @param list<string> $wanted       the tags kept
     * @param list<string> $nodeTriggers a node is read when it has one of these keys
     * @param list<string> $wayTriggers  a way is read when it has one of these keys
     */
    public function __construct(array $wanted, array $nodeTriggers, array $wayTriggers)
    {
        $this->wanted = array_fill_keys($wanted, true);
        $this->nodeTriggers = array_fill_keys($nodeTriggers, true);
        $this->wayTriggers = array_fill_keys($wayTriggers, true);
    }

    /** Whether a group of the block holds nodes (plain or dense). */
    public static function isNodes(int $field): bool
    {
        return $field === 1 || $field === 2;
    }

    /**
     * `[lat, lon]` in 1e-6 degree of a stored coordinate.
     *
     * @return array{int,int}
     */
    public static function coordinate(Block $b, int $lat, int $lon): array
    {
        return [self::scale($b->latOffset + $b->granularity * $lat), self::scale($b->lonOffset + $b->granularity * $lon)];
    }

    /**
     * The nodes that have a trigger key, with their wanted tags, in file order.
     *
     * @return Generator<int,array{int,int,int,array<string,string>}> id, lat, lon in 1e-6 degree, tags
     */
    public function nodes(Block $b): Generator
    {
        $index = $this->index($b, $this->nodeTriggers);

        if ($index === []) {
            return;
        }

        $keys = $this->index($b, $this->wanted);
        $d = $b->data;

        foreach ($b->groups as [$field, $p, $end]) {
            if ($field === 2) {
                yield from $this->dense($b, $d, $p, $end, $index, $keys);
            } elseif ($field === 1) {
                $node = $this->plainNode($b, $d, $p, $end, $index, $keys);

                if ($node !== null) {
                    yield $node;
                }
            }
        }
    }

    /**
     * The ways that have a trigger key, with their wanted tags and node references, in file order.
     *
     * @return Generator<int,array{int,array<string,string>,list<int>}> id, tags, node ids
     */
    public function ways(Block $b): Generator
    {
        $triggers = $this->index($b, $this->wayTriggers);

        if ($triggers === []) {
            return;
        }

        $keys = $this->index($b, $this->wanted);
        $d = $b->data;
        $st = $b->strings;

        foreach ($b->groups as [$field, $p, $end]) {
            if ($field !== 3) {
                continue;
            }

            $id = 0;
            $k = null;
            $v = null;
            $refs = null;

            while ($p < $end) {
                $key = Wire::varint($d, $p);
                $wt = $key & 7;

                if ($wt !== 2) {
                    $value = Wire::varint($d, $p);
                    $id = $key >> 3 === 1 ? $value : $id;
                    continue;
                }

                $len = Wire::length($d, $p);

                match ($key >> 3) {
                    2 => $k = [$p, $p + $len],
                    3 => $v = [$p, $p + $len],
                    8 => $refs = [$p, $p + $len],
                    default => null,
                };
                $p += $len;
            }

            if ($k === null || $v === null) {
                continue;
            }

            $kk = Wire::packed($d, $k[0], $k[1]);
            $hit = false;

            foreach ($kk as $i) {
                if (isset($triggers[$i])) {
                    $hit = true;
                    break;
                }
            }

            if (!$hit) {
                continue;
            }

            $vv = Wire::packed($d, $v[0], $v[1]);
            $tags = [];

            foreach ($kk as $position => $i) {
                if (isset($keys[$i])) {
                    $tags[$keys[$i]] = $st[$vv[$position] ?? 0] ?? '';
                }
            }

            yield [$id, $tags, $refs === null ? [] : Wire::packedDelta($d, $refs[0], $refs[1])];
        }
    }

    /**
     * Positions of the nodes of a block the caller wants, found without decoding the coordinates of the others: the
     * ids of a group are decoded, and `$pull` is asked for the wanted ones between its first and last id.
     *
     * @param callable(int,int):array<int,mixed> $pull ids (as keys) wanted among those from the first to the last id of a group
     *
     * @return Generator<int,array{int,int,int}> id, lat, lon in 1e-6 degree
     */
    public static function positions(Block $b, callable $pull): Generator
    {
        $d = $b->data;

        foreach ($b->groups as [$field, $p, $end]) {
            if ($field === 2) {
                $ids = null;
                $lat = null;
                $lon = null;

                while ($p < $end) {
                    $key = Wire::varint($d, $p);

                    if (($key & 7) !== 2) {
                        Wire::skip($d, $p, $key & 7);
                        continue;
                    }

                    $len = Wire::length($d, $p);

                    match ($key >> 3) {
                        1 => $ids = Wire::packedDelta($d, $p, $p + $len),
                        8 => $lat = [$p, $p + $len],
                        9 => $lon = [$p, $p + $len],
                        default => null,
                    };
                    $p += $len;
                }

                if ($ids === null || $ids === [] || $lat === null || $lon === null) {
                    continue;
                }

                $want = $pull($ids[0], $ids[count($ids) - 1]);

                if ($want === []) {
                    continue;
                }

                $lats = Wire::packedDelta($d, $lat[0], $lat[1]);
                $lons = Wire::packedDelta($d, $lon[0], $lon[1]);

                foreach ($ids as $i => $id) {
                    if (isset($want[$id])) {
                        [$la, $lo] = self::coordinate($b, $lats[$i], $lons[$i]);
                        yield [$id, $la, $lo];
                    }
                }
            } elseif ($field === 1) {
                $node = self::plainPosition($b, $d, $p, $end);

                if ($node !== null && isset($pull($node[0], $node[0])[$node[0]])) {
                    yield $node;
                }
            }
        }
    }

    /**
     * @param array<int,string>   $triggers string index => key, of the trigger keys of the block
     * @param array<int,string>   $keys     string index => key, of the wanted keys of the block
     *
     * @return Generator<int,array{int,int,int,array<string,string>}>
     */
    private function dense(Block $b, string $d, int $p, int $end, array $triggers, array $keys): Generator
    {
        $ids = null;
        $lat = null;
        $lon = null;
        $kv = null;

        while ($p < $end) {
            $key = Wire::varint($d, $p);

            if (($key & 7) !== 2) {
                Wire::skip($d, $p, $key & 7);
                continue;
            }

            $len = Wire::length($d, $p);

            match ($key >> 3) {
                1 => $ids = [$p, $p + $len],
                8 => $lat = [$p, $p + $len],
                9 => $lon = [$p, $p + $len],
                10 => $kv = [$p, $p + $len],
                default => null,
            };
            $p += $len;
        }

        if ($kv === null || $ids === null || $lat === null || $lon === null) {
            return;
        }

        $pairs = Wire::packed($d, $kv[0], $kv[1]);
        $count = count($pairs);
        $st = $b->strings;
        $hits = [];
        $tagsOf = [];
        $node = 0;
        $trigger = false;
        $tags = [];

        for ($q = 0; $q < $count; ++$q) {
            if ($pairs[$q] === 0) {
                if ($trigger) {
                    $hits[$node] = true;
                    $tagsOf[$node] = $tags;
                }

                ++$node;
                $trigger = false;
                $tags = [];
                continue;
            }

            $k = $pairs[$q];
            $value = $pairs[++$q] ?? 0;

            if (isset($triggers[$k])) {
                $trigger = true;
            }

            if (isset($keys[$k])) {
                $tags[$keys[$k]] = $st[$value] ?? '';
            }
        }

        if ($hits === []) {
            return;
        }

        $idList = Wire::packedDelta($d, $ids[0], $ids[1]);
        $lats = Wire::packedDelta($d, $lat[0], $lat[1]);
        $lons = Wire::packedDelta($d, $lon[0], $lon[1]);

        foreach ($tagsOf as $i => $t) {
            [$la, $lo] = self::coordinate($b, $lats[$i], $lons[$i]);
            yield [$idList[$i], $la, $lo, $t];
        }
    }

    /**
     * @param array<int,string> $triggers
     * @param array<int,string> $keys
     *
     * @return array{int,int,int,array<string,string>}|null
     */
    private function plainNode(Block $b, string $d, int $p, int $end, array $triggers, array $keys): ?array
    {
        $id = 0;
        $lat = 0;
        $lon = 0;
        $k = [];
        $v = [];

        while ($p < $end) {
            $key = Wire::varint($d, $p);
            $wt = $key & 7;

            if ($wt === 0) {
                $value = Wire::varint($d, $p);

                match ($key >> 3) {
                    1 => $id = Wire::zigzag($value),
                    8 => $lat = Wire::zigzag($value),
                    9 => $lon = Wire::zigzag($value),
                    default => null,
                };
                continue;
            }

            if ($wt !== 2) {
                Wire::skip($d, $p, $wt);
                continue;
            }

            $len = Wire::length($d, $p);

            match ($key >> 3) {
                2 => $k = Wire::packed($d, $p, $p + $len),
                3 => $v = Wire::packed($d, $p, $p + $len),
                default => null,
            };
            $p += $len;
        }

        $trigger = false;
        $tags = [];

        foreach ($k as $position => $i) {
            if (isset($triggers[$i])) {
                $trigger = true;
            }

            if (isset($keys[$i])) {
                $tags[$keys[$i]] = $b->strings[$v[$position] ?? 0] ?? '';
            }
        }

        if (!$trigger) {
            return null;
        }

        [$la, $lo] = self::coordinate($b, $lat, $lon);

        return [$id, $la, $lo, $tags];
    }

    /** @return array{int,int,int}|null */
    private static function plainPosition(Block $b, string $d, int $p, int $end): ?array
    {
        $id = null;
        $lat = 0;
        $lon = 0;

        while ($p < $end) {
            $key = Wire::varint($d, $p);
            $wt = $key & 7;

            if ($wt === 0) {
                $value = Wire::varint($d, $p);

                match ($key >> 3) {
                    1 => $id = Wire::zigzag($value),
                    8 => $lat = Wire::zigzag($value),
                    9 => $lon = Wire::zigzag($value),
                    default => null,
                };
            } else {
                Wire::skip($d, $p, $wt);
            }
        }

        if ($id === null) {
            return null;
        }

        [$la, $lo] = self::coordinate($b, $lat, $lon);

        return [$id, $la, $lo];
    }

    /**
     * @param array<string,true> $names
     *
     * @return array<int,string> string index => key, for the keys of `$names` the block's string table holds
     */
    private function index(Block $b, array $names): array
    {
        $out = [];

        foreach ($b->strings as $i => $s) {
            if (isset($names[$s])) {
                $out[$i] = $s;
            }
        }

        return $out;
    }

    /** 1e-9 degree to 1e-6, rounding half away from zero (as the routing builder does). */
    private static function scale(int $nano): int
    {
        $tenth = intdiv($nano, 100);

        return intdiv($tenth + ($tenth >= 0 ? 5 : -5), 10);
    }
}
