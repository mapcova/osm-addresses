<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Pbf;

/** Protocol Buffers wire format, just what an OSM PBF file needs. */
final class Wire
{
    /** Reads a varint at `$p` and moves `$p` past it. */
    /**
     * @throws PbfFormatException `err_osm_truncated` for a varint past the end of the data or longer than ten bytes
     */
    public static function varint(string $s, int &$p): int
    {
        if (!isset($s[$p])) {
            throw new PbfFormatException('err_osm_truncated', 'a varint past the end of the data');
        }

        $b = \ord($s[$p++]);
        $v = $b & 0x7f;
        $sh = 7;
        while ($b >= 0x80) {
            if ($sh > 63 || !isset($s[$p])) {
                throw new PbfFormatException('err_osm_truncated', 'a varint longer than ten bytes or past the end of the data');
            }
            $b = \ord($s[$p++]);
            $v |= ($b & 0x7f) << $sh;
            $sh += 7;
        }

        return $v;
    }

    /**
     * The length of a length-delimited field: never negative and never past the end of the data, so a damaged or crafted
     * file cannot send a parser backwards (a loop) or beyond its buffer.
     *
     * @throws PbfFormatException `err_osm_truncated`
     */
    public static function length(string $s, int &$p): int
    {
        $len = self::varint($s, $p);

        if ($len < 0 || $len > \strlen($s) - $p) {
            throw new PbfFormatException('err_osm_truncated', "a field of $len bytes at $p does not fit in its data");
        }

        return $len;
    }

    /** Skips the value of a field of wire type `$wt` (the tag is already read). */
    public static function skip(string $s, int &$p, int $wt): void
    {
        switch ($wt) {
            case 0:
                self::varint($s, $p);
                break;
            case 1:
                $p += 8;
                break;
            case 2:
                $p += self::length($s, $p);
                break;
            case 5:
                $p += 4;
                break;
            default:
                throw new PbfFormatException('err_osm_truncated', "unsupported protobuf wire type $wt");
        }
    }

    /** @return list<int> packed varints of `$s[$p..$end)` */
    public static function packed(string $s, int $p, int $end): array
    {
        $out = [];
        while ($p < $end) {
            $b = \ord($s[$p++]);
            $v = $b & 0x7f;
            $sh = 7;
            while ($b >= 0x80) {
                $b = \ord($s[$p++]);
                $v |= ($b & 0x7f) << $sh;
                $sh += 7;
            }
            $out[] = $v;
        }

        return $out;
    }

    /** @return list<int> packed zigzag varints, delta-decoded (running sum) */
    public static function packedDelta(string $s, int $p, int $end): array
    {
        $out = [];
        $acc = 0;
        while ($p < $end) {
            $b = \ord($s[$p++]);
            $v = $b & 0x7f;
            $sh = 7;
            while ($b >= 0x80) {
                $b = \ord($s[$p++]);
                $v |= ($b & 0x7f) << $sh;
                $sh += 7;
            }
            $acc += ($v >> 1) ^ -($v & 1);
            $out[] = $acc;
        }

        return $out;
    }

    /** `sint` zigzag decoding of one value. */
    public static function zigzag(int $v): int
    {
        return ($v >> 1) ^ -($v & 1);
    }
}
