<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Osm;

use RuntimeException;

/**
 * The border between Northern Ireland (the United Kingdom's, built into GB) and the Republic (IE). The two Geofabrik
 * extracts overlap along it and their edges "occasionally take liberties", so each build keeps only its own side: GB drops
 * what lies on the island outside Northern Ireland, IE drops what lies inside it.
 *
 * The polygon is `src/resources/northern-ireland.json`, made by `tools/make-ni-border.php` from OpenStreetMap
 * (relation 156393, ODbL 1.0), simplified to 10 m. Only a position inside the island's box is tested: a grid of 0.01 degree
 * cells, each found inside or outside once (at its centre) unless an edge of the polygon crosses it, and only the cells on
 * an edge run the exact test.
 */
final class IrelandBorder
{
    private const CELL_E6 = 10_000;

    /** min lat, max lat, min lon, max lon (e6) of Northern Ireland, with a margin. */
    private const BOX = [53_900_000, 55_500_000, -8_300_000, -5_300_000];

    /**
     * Where the island of Ireland can be met in an extract that is mostly Great Britain: the island south of 55.0, and the
     * north coast (Inishowen) west of -6.7. Kintyre, Islay and Jura are north of 55.0 and east of -6.7.
     */
    private const ISLAND = [[53_900_000, 55_000_000, -8_300_000, -5_300_000], [55_000_000, 55_450_000, -8_300_000, -6_700_000]];

    /** @var array<int,bool> cells an edge crosses */
    private array $edge = [];

    /** @var array<int,bool> cells without an edge: inside or not */
    private array $known = [];

    /** @param list<list<array{int,int}>> $rings the outer rings, as `[lat_e6, lon_e6]` points */
    public function __construct(private readonly array $rings)
    {
        foreach ($rings as $ring) {
            for ($i = 0, $n = count($ring); $i < $n; ++$i) {
                [$lat1, $lon1] = $ring[$i];
                [$lat2, $lon2] = $ring[($i + 1) % $n];

                for ($row = self::row(min($lat1, $lat2)); $row <= self::row(max($lat1, $lat2)); ++$row) {
                    for ($column = self::column(min($lon1, $lon2)); $column <= self::column(max($lon1, $lon2)); ++$column) {
                        $this->edge[$row * 100_000 + $column] = true;
                    }
                }
            }
        }
    }

    public static function load(?string $path = null): self
    {
        $path ??= dirname(__DIR__) . '/resources/northern-ireland.json';
        $json = @file_get_contents($path);
        $data = $json === false ? null : json_decode($json, true);

        if (!is_array($data) || !is_array($data['rings'] ?? null)) {
            throw new RuntimeException('Cannot read ' . basename($path));
        }

        /** @var list<list<array{int,int}>> $rings */
        $rings = $data['rings'];

        return new self($rings);
    }

    /** Whether a position is in Northern Ireland. */
    public function contains(int $latE6, int $lonE6): bool
    {
        [$minLat, $maxLat, $minLon, $maxLon] = self::BOX;

        if ($latE6 < $minLat || $latE6 > $maxLat || $lonE6 < $minLon || $lonE6 > $maxLon) {
            return false;
        }

        $cell = self::row($latE6) * 100_000 + self::column($lonE6);

        if (isset($this->edge[$cell])) {
            return $this->exact($latE6, $lonE6);
        }

        return $this->known[$cell] ??= $this->exact(
            self::row($latE6) * self::CELL_E6 + intdiv(self::CELL_E6, 2) - 90_000_000,
            self::column($lonE6) * self::CELL_E6 + intdiv(self::CELL_E6, 2) - 180_000_000,
        );
    }

    /** Whether a position is on the island of Ireland and not in Northern Ireland: the Republic. */
    public function inRepublic(int $latE6, int $lonE6): bool
    {
        foreach (self::ISLAND as [$minLat, $maxLat, $minLon, $maxLon]) {
            if ($latE6 >= $minLat && $latE6 < $maxLat && $lonE6 >= $minLon && $lonE6 <= $maxLon) {
                return !$this->contains($latE6, $lonE6);
            }
        }

        return false;
    }

    private function exact(int $latE6, int $lonE6): bool
    {
        $inside = false;

        foreach ($this->rings as $ring) {
            for ($i = 0, $n = count($ring), $j = $n - 1; $i < $n; $j = $i++) {
                [$lat1, $lon1] = $ring[$i];
                [$lat2, $lon2] = $ring[$j];

                if (($lat1 > $latE6) !== ($lat2 > $latE6) && $lonE6 < ($lon2 - $lon1) * ($latE6 - $lat1) / ($lat2 - $lat1) + $lon1) {
                    $inside = !$inside;
                }
            }
        }

        return $inside;
    }

    private static function row(int $latE6): int
    {
        return intdiv($latE6 + 90_000_000, self::CELL_E6);
    }

    private static function column(int $lonE6): int
    {
        return intdiv($lonE6 + 180_000_000, self::CELL_E6);
    }
}
