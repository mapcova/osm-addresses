<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Pbf;

use RuntimeException;

/** `unpack` with typed results: truncated or malformed data throws instead of turning into a wrong number. */
final class Bin
{
    /** @return array<int|string,mixed> */
    private static function raw(string $format, string $s, int $off): array
    {
        $u = @unpack($format, $s, $off); // a short string is reported by the exception below, not by a warning
        if ($u === false) {
            throw new RuntimeException('truncated binary data');
        }

        return $u;
    }

    /** @return array<string,int> the fields of an integer-only format, such as `Pid/Vcount/Cflags` */
    public static function ints(string $format, string $s, int $off = 0): array
    {
        $out = [];
        foreach (self::raw($format, $s, $off) as $k => $v) {
            if (!\is_int($v)) {
                throw new RuntimeException('not an integer field');
            }
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /** @return list<int> every u32 of the string */
    public static function allU32(string $s): array
    {
        return $s === '' ? [] : self::list('V*', $s);
    }

    /** @return list<int> every u64 of the string */
    public static function allU64(string $s): array
    {
        return $s === '' ? [] : self::list('P*', $s);
    }

    /**
     * `pack($code . '*', ...$values)` in chunks: spreading a big array into the arguments of `pack` costs a second copy
     * of it, and a call with millions of arguments fails.
     *
     * @param list<int> $values
     */
    public static function packAll(string $code, array $values): string
    {
        $out = '';
        foreach (array_chunk($values, 4096) as $part) {
            $out .= pack($code . '*', ...$part);
        }

        return $out;
    }

    /** @return list<int> */
    private static function list(string $format, string $s): array
    {
        $out = [];
        foreach (self::raw($format, $s, 0) as $v) {
            if (!\is_int($v)) {
                throw new RuntimeException('not an integer field');
            }
            $out[] = $v;
        }

        return $out;
    }
}
