<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Osm;

use MapCova\OsmAddresses\Build\TsvRecordReader;

/**
 * The place nodes of an extract (`city`, `town`, `village`, `hamlet`) in a grid of 0.05 degree cells, to name the
 * locality of an address that gives none: the nearest place within the radius of its type.
 */
final class PlaceIndex
{
    /** Metres a place of each type names the addresses around it. */
    public const RADIUS = ['city' => 15_000, 'town' => 8_000, 'village' => 3_000, 'hamlet' => 1_500];

    private const CELL_E6 = 50_000;
    private const METRES_PER_DEGREE = 111_320.0;

    /** @var array<int,list<array{string,string,int,int,array<string,string>}>> cell => places (type, name, lat, lon, names by language) */
    private array $cells = [];

    private int $count = 0;

    public function count(): int
    {
        return $this->count;
    }

    /** @param array<string,string> $names the name of the place in other languages, by language code */
    public function add(string $type, string $name, int $latE6, int $lonE6, array $names = []): void
    {
        if (!isset(self::RADIUS[$type]) || $name === '') {
            return;
        }

        $this->cells[self::cell($latE6, $lonE6)][] = [$type, $name, $latE6, $lonE6, $names];
        ++$this->count;
    }

    /**
     * The places file of the extraction: `place`, `name`, `lat_e6`, `lon_e6`, then a column for each language.
     */
    public static function fromFile(string $path): self
    {
        $index = new self();
        $reader = new TsvRecordReader($path);
        $header = $reader->header() ?? [];
        $languages = array_slice($header, 4);

        while (($record = $reader->next()) !== null) {
            $names = [];

            foreach ($languages as $position => $language) {
                if (($record[4 + $position] ?? '') !== '') {
                    $names[(string) $language] = (string) $record[4 + $position];
                }
            }

            $index->add((string) $record[0], (string) ($record[1] ?? ''), (int) ($record[2] ?? 0), (int) ($record[3] ?? 0), $names);
        }

        return $index;
    }

    /**
     * The nearest place that names this position.
     *
     * @return array{type:string,name:string,lat:int,lon:int,names:array<string,string>,metres:float}|null
     */
    public function nearest(int $latE6, int $lonE6): ?array
    {
        $cosine = max(0.01, cos(deg2rad($latE6 / 1e6)));
        $reach = max(self::RADIUS);
        $latCells = (int) ceil($reach / self::METRES_PER_DEGREE * 1e6 / self::CELL_E6);
        $lonCells = (int) ceil($reach / (self::METRES_PER_DEGREE * $cosine) * 1e6 / self::CELL_E6);
        $row = intdiv($latE6 + 90_000_000, self::CELL_E6);
        $column = intdiv($lonE6 + 180_000_000, self::CELL_E6);
        $best = null;

        for ($r = $row - $latCells; $r <= $row + $latCells; ++$r) {
            for ($c = $column - $lonCells; $c <= $column + $lonCells; ++$c) {
                foreach ($this->cells[$r * 100_000 + $c] ?? [] as [$type, $name, $lat, $lon, $names]) {
                    $dy = ($lat - $latE6) / 1e6 * self::METRES_PER_DEGREE;
                    $dx = ($lon - $lonE6) / 1e6 * self::METRES_PER_DEGREE * $cosine;
                    $metres = sqrt($dx * $dx + $dy * $dy);

                    if ($metres <= self::RADIUS[$type] && ($best === null || $metres < $best['metres'])) {
                        $best = ['type' => $type, 'name' => $name, 'lat' => $lat, 'lon' => $lon, 'names' => $names, 'metres' => $metres];
                    }
                }
            }
        }

        return $best;
    }

    private static function cell(int $latE6, int $lonE6): int
    {
        return intdiv($latE6 + 90_000_000, self::CELL_E6) * 100_000 + intdiv($lonE6 + 180_000_000, self::CELL_E6);
    }
}
