#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Makes src/resources/northern-ireland.json, the border between Northern Ireland and the Republic that the
 * United Kingdom and Ireland builds use to keep each its own side (src/Osm/IrelandBorder.php).
 *
 *   php tools/make-ni-border.php <overpass.json> [<out.json>] [<metres>]
 *
 * <overpass.json> is the answer of the Overpass API to `[out:json];rel(156393);out geom;` (OpenStreetMap relation 156393,
 * Northern Ireland; ODbL 1.0), fetched by hand:
 *
 *   curl --data-urlencode 'data=[out:json][timeout:120];rel(156393);out geom;' https://overpass-api.de/api/interpreter -o ni.json
 *
 * The outer ways are joined into rings and each ring is simplified with Douglas-Peucker (10 m by default). The output
 * holds the points as `[lat_e6, lon_e6]`. Development tool: never run by the server.
 */

if (PHP_SAPI !== 'cli' || $argc < 2) {
    fwrite(STDERR, "usage: php tools/make-ni-border.php <overpass.json> [<out.json>] [<metres>]\n");
    exit(2);
}

$input = $argv[1];
$output = $argv[2] ?? dirname(__DIR__) . '/src/resources/northern-ireland.json';
$tolerance = (float) ($argv[3] ?? 10);
$data = json_decode((string) file_get_contents($input), true);
$members = $data['elements'][0]['members'] ?? null;

if (!is_array($members)) {
    fwrite(STDERR, "no relation members in the input\n");
    exit(1);
}

/** @var list<list<array{float,float}>> $ways */
$ways = [];

foreach ($members as $member) {
    if (($member['type'] ?? '') === 'way' && ($member['role'] ?? '') === 'outer' && is_array($member['geometry'] ?? null) && count($member['geometry']) > 1) {
        $ways[] = array_map(static fn (array $p): array => [(float) $p['lat'], (float) $p['lon']], $member['geometry']);
    }
}

$key = static fn (array $p): string => sprintf('%.7f,%.7f', $p[0], $p[1]);
$rings = [];

while ($ways !== []) {
    $ring = array_shift($ways);

    while ($key($ring[0]) !== $key($ring[count($ring) - 1])) {
        $end = $key($ring[count($ring) - 1]);
        $found = false;

        foreach ($ways as $i => $way) {
            if ($key($way[0]) === $end) {
                array_pop($ring);
                $ring = [...$ring, ...$way];
            } elseif ($key($way[count($way) - 1]) === $end) {
                array_pop($ring);
                $ring = [...$ring, ...array_reverse($way)];
            } else {
                continue;
            }

            unset($ways[$i]);
            $found = true;
            break;
        }

        if (!$found) {
            fwrite(STDERR, "a ring does not close\n");
            exit(1);
        }
    }

    array_pop($ring);
    $rings[] = $ring;
}

/** Distance in metres from $p to the segment $a-$b, on a plane scaled at the latitude of $p. */
function distance(array $p, array $a, array $b): float
{
    $k = cos(deg2rad($p[0]));
    [$px, $py, $ax, $ay, $bx, $by] = [$p[1] * $k, $p[0], $a[1] * $k, $a[0], $b[1] * $k, $b[0]];
    $dx = $bx - $ax;
    $dy = $by - $ay;
    $t = $dx === 0.0 && $dy === 0.0 ? 0.0 : max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / ($dx * $dx + $dy * $dy)));

    return hypot($px - ($ax + $t * $dx), $py - ($ay + $t * $dy)) * 111_320.0;
}

/**
 * Douglas-Peucker without recursion.
 *
 * @param list<array{float,float}> $points
 *
 * @return list<array{float,float}>
 */
function simplify(array $points, float $metres): array
{
    $keep = [0 => true, count($points) - 1 => true];
    $stack = [[0, count($points) - 1]];

    while ($stack !== []) {
        [$first, $last] = array_pop($stack);
        $worst = 0.0;
        $at = -1;

        for ($i = $first + 1; $i < $last; ++$i) {
            $d = distance($points[$i], $points[$first], $points[$last]);

            if ($d > $worst) {
                [$worst, $at] = [$d, $i];
            }
        }

        if ($at >= 0 && $worst > $metres) {
            $keep[$at] = true;
            $stack[] = [$first, $at];
            $stack[] = [$at, $last];
        }
    }

    ksort($keep);

    return array_map(static fn (int $i): array => $points[$i], array_keys($keep));
}

$out = [];
$before = 0;

foreach ($rings as $ring) {
    $before += count($ring);
    $closed = [...$ring, $ring[0]];
    $simple = simplify($closed, $tolerance);
    array_pop($simple);

    if (count($simple) >= 3) {
        $out[] = array_map(static fn (array $p): array => [(int) round($p[0] * 1e6), (int) round($p[1] * 1e6)], $simple);
    }
}

$after = array_sum(array_map('count', $out));
$json = json_encode(
    ['source' => 'OpenStreetMap relation 156393 (Northern Ireland), ODbL 1.0, simplified to ' . $tolerance . ' m', 'rings' => $out],
    JSON_THROW_ON_ERROR,
);
file_put_contents($output, $json . "\n");
fwrite(STDERR, sprintf("%d ring(s), %d points kept of %d, %d bytes\n", count($out), $after, $before, strlen($json)));
