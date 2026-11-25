<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Sources;

/**
 * Ireland: the Republic, built from the OpenStreetMap extract of Ireland and Northern Ireland at Geofabrik (ODbL 1.0;
 * 418 902 904 bytes on 2026-11-24, strong ETag, ranges), minus Northern Ireland (the United Kingdom's, built into GB).
 *
 * An Eircode is the property of a company and is not in OpenStreetMap in bulk (about 16 000 objects carry a postcode): most
 * streets have none. When it is there, the street holds the routing key (`D02`) and the address the unique identifier (`X285`).
 * `Dublin 2`, `Dublin 6W` written as a postcode are the routing keys `D02`, `D6W` with no identifier.
 * The names in Irish (`name:ga`) and in English (`name:en`) are the other forms of a street and of a town.
 *
 * Measured on the extract of Cork (BBBike, 22 212 472 bytes: 17 388 rows in 2 295 streets, one database of about 0.9 MB, 1.4 s on a laptop,
 * 25 MB of memory) and scaled to the island by the numbered objects of the extract (taginfo: 493 000, 94 000 of them in Northern Ireland, so
 * about 365 000 rows). In Cork 90 % of the numbered addresses have no postcode (there are 592 streets with a routing key in 2 295: the
 * Eircode is not open data) and 40 % take their city from the nearest place; the street and the town have a form in the other
 * language for 94 % of the streets of Cork.
 */
final class IrelandSource extends OsmSource
{
    public const URL = 'https://download.geofabrik.de/europe/ireland-and-northern-ireland/ireland-and-northern-ireland-latest.osm.pbf';

    /** min lat, max lat, min lon, max lon (e6) of the island with its islands, and a margin. */
    private const BOX = [51_300_000, 55_500_000, -10_800_000, -5_300_000];

    private const ROUTING_KEY = '/^([ACDEFHKNPRTVWXY]\d{2}|D6W)$/';

    public function country(): string
    {
        return 'IE';
    }

    public function name(): string
    {
        return 'OpenStreetMap, Ireland and Northern Ireland (Geofabrik)';
    }

    public function url(): string
    {
        return 'https://download.geofabrik.de/europe/ireland-and-northern-ireland.html';
    }

    /** The extract: 418 902 904 bytes on 2026-11-24. */
    public function approxBytes(): int
    {
        return 418_902_904;
    }

    public function largestChunkBytes(): int
    {
        return $this->approxBytes();
    }

    /** 23 s of extraction (1.2 s for Cork's 21 MB) and 4 s from the ingestion to the publication (11.5 s a million rows) on a laptop, times 3. */
    public function estimatedBuildSeconds(): int
    {
        return 90;
    }

    /** 0.042 measured on Cork (54 bytes a row), about 365 000 rows in 419 MB: 0.05. */
    public function finalRatio(): float
    {
        return 0.05;
    }

    /** 1.11 measured on Cork (133 bytes a row derived from the extract), about 365 000 rows: 1.2. */
    public function stagingRatio(): float
    {
        return 1.2;
    }

    /** About 365 000 rows: 90 000 a bucket. */
    public function bucketCount(): int
    {
        return 4;
    }

    public function chunks(): array
    {
        return [new Chunk('ie', self::URL, Chunk::PLAIN)];
    }

    public function languages(): array
    {
        return ['ga', 'en'];
    }

    protected function accepts(int $latE6, int $lonE6): bool
    {
        [$minLat, $maxLat, $minLon, $maxLon] = self::BOX;

        return $latE6 >= $minLat && $latE6 <= $maxLat && $lonE6 >= $minLon && $lonE6 <= $maxLon && !self::border()->contains($latE6, $lonE6);
    }

    protected function citiesFollowPostcodes(): bool
    {
        return false;
    }

    protected function namesAreForms(): bool
    {
        return true;
    }

    protected function postcode(string $text): array
    {
        $compact = strtoupper((string) preg_replace('/[\s.]+/', '', $text));

        if (preg_match('/^DUBLIN(\d{1,2}|6W)$/', $compact, $match) === 1) {
            $key = $match[1] === '6W' ? 'D6W' : 'D' . str_pad($match[1], 2, '0', STR_PAD_LEFT);

            return $key === 'D6W' || ((int) $match[1] >= 1 && (int) $match[1] <= 24) ? [$key, null] : [null, null];
        }

        if (preg_match('/^(D6W|[A-Z]\d{2})([0-9A-Z]{4})?$/', $compact, $match) === 1 && preg_match(self::ROUTING_KEY, $match[1]) === 1) {
            $unique = $match[2] ?? '';

            return $unique === '' || preg_match('/^[0-9ACDEFHKNPRTVWXY]{4}$/', $unique) === 1 ? [$match[1], $unique === '' ? null : $unique] : [null, null];
        }

        return [null, null];
    }
}
