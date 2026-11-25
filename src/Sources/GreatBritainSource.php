<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Sources;

/**
 * Great Britain: the United Kingdom of Great Britain and Northern Ireland, built from the OpenStreetMap extract of the United
 * Kingdom at Geofabrik (ODbL 1.0; 2 292 299 017 bytes on 2026-11-24, strong ETag, ranges), minus what the extract holds of the
 * Republic. No open national address file exists; OpenStreetMap has about one address in five (see the README).
 *
 * The street's postcode is the outward code (`SW1A`), the address's `pc` the inward code (`1AA`): a reader shows
 * `SW1A 1AA`. The text is upper-cased, spaces dropped, and the outward code must be on one of the postcode areas of Great Britain
 * and Northern Ireland ({@see self::POSTCODE_AREAS}, `BT` included); `GIR 0AA`, the Crown dependencies' and anything else
 * is no postcode.
 *
 * Measured on Wales (Geofabrik, 151 742 205 bytes, 2026-11-24; 189 606 rows in 29 225 streets, one database of 10 711 040 bytes,
 * 10 s on a laptop, 68 MB of memory at the peak) and scaled to the United Kingdom by the number of numbered buildings (taginfo: 5.4 million
 * numbered objects, 2.0 per KB of the extract against 1.2 in Wales, so about 5 million rows). Of the numbered addresses of Wales, 26 %
 * have no postcode, 14 % take their city from the nearest place and 2 % from the post town; the extraction drops 11 % of the objects
 * that have a number (no street or place: 24 591). Streets that have a postcode for some of their addresses and none for the others
 * are 4.97 % of the street rows (5.3 % of the street names): a fill of the missing postcodes from the
 * other rows of the street is not built.
 */
final class GreatBritainSource extends OsmSource
{
    public const URL = 'https://download.geofabrik.de/europe/united-kingdom/united-kingdom-latest.osm.pbf';

    /** The 121 postcode areas of Great Britain and Northern Ireland (the letters before the first digit), "BT" included; the Crown dependencies' GY, JE and IM are other countries'. */
    private const POSTCODE_AREAS = [
        'AB', 'AL', 'B', 'BA', 'BB', 'BD', 'BH', 'BL', 'BN', 'BR', 'BS', 'BT', 'CA', 'CB', 'CF', 'CH', 'CM', 'CO', 'CR', 'CT', 'CV', 'CW',
        'DA', 'DD', 'DE', 'DG', 'DH', 'DL', 'DN', 'DT', 'DY', 'E', 'EC', 'EH', 'EN', 'EX', 'FK', 'FY', 'G', 'GL', 'GU', 'HA', 'HD', 'HG',
        'HP', 'HR', 'HS', 'HU', 'HX', 'IG', 'IP', 'IV', 'KA', 'KT', 'KW', 'KY', 'L', 'LA', 'LD', 'LE', 'LL', 'LN', 'LS', 'LU', 'M', 'ME',
        'MK', 'ML', 'N', 'NE', 'NG', 'NN', 'NP', 'NR', 'NW', 'OL', 'OX', 'PA', 'PE', 'PH', 'PL', 'PO', 'PR', 'RG', 'RH', 'RM', 'S', 'SA',
        'SE', 'SG', 'SK', 'SL', 'SM', 'SN', 'SO', 'SP', 'SR', 'SS', 'ST', 'SW', 'SY', 'TA', 'TD', 'TF', 'TN', 'TQ', 'TR', 'TS', 'TW', 'UB',
        'W', 'WA', 'WC', 'WD', 'WF', 'WN', 'WR', 'WS', 'WV', 'YO', 'ZE',
    ];

    /** min lat, max lat, min lon, max lon (e6) of Great Britain with Shetland and St Kilda, and Northern Ireland. */
    private const BOX = [49_800_000, 61_000_000, -8_700_000, 2_000_000];

    public function country(): string
    {
        return 'GB';
    }

    public function name(): string
    {
        return 'OpenStreetMap, United Kingdom (Geofabrik)';
    }

    public function url(): string
    {
        return 'https://download.geofabrik.de/europe/united-kingdom.html';
    }

    /** The extract: 2 292 299 017 bytes on 2026-11-24. */
    public function approxBytes(): int
    {
        return 2_292_299_017;
    }

    public function largestChunkBytes(): int
    {
        return $this->approxBytes();
    }

    /**
     * 116 s of extraction (7.7 s for Wales' 152 MB) and 63 s from the ingestion to the publication (2.4 s for Wales' 190 000 rows, about
     * 5 million expected) on a laptop, times 3.
     */
    public function estimatedBuildSeconds(): int
    {
        return 540;
    }

    /** 0.071 measured on Wales (56 bytes a row), about 5 million rows in 2.29 GB: 0.12. */
    public function finalRatio(): float
    {
        return 0.12;
    }

    /** 1.17 measured on Wales (the extract and what the extraction derives from it, 130 bytes a row), more rows to the byte here: 1.3. */
    public function stagingRatio(): float
    {
        return 1.3;
    }

    /** About 5 million rows: 210 000 a bucket. */
    public function bucketCount(): int
    {
        return 24;
    }

    public function chunks(): array
    {
        return [new Chunk('gb', self::URL, Chunk::PLAIN)];
    }

    public function languages(): array
    {
        return ['cy', 'gd'];
    }

    protected function accepts(int $latE6, int $lonE6): bool
    {
        [$minLat, $maxLat, $minLon, $maxLon] = self::BOX;

        return $latE6 >= $minLat && $latE6 <= $maxLat && $lonE6 >= $minLon && $lonE6 <= $maxLon && !self::border()->inRepublic($latE6, $lonE6);
    }

    protected function citiesFollowPostcodes(): bool
    {
        return true;
    }

    protected function postcode(string $text): array
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $text));

        if (preg_match('/^(.+)(\d[A-Z]{2})$/', $compact, $match) !== 1 || preg_match('/^([A-Z]{1,2})\d[A-Z\d]?$/', $match[1], $area) !== 1 || !in_array($area[1], self::POSTCODE_AREAS, true)) {
            return [null, null];
        }

        return [$match[1], $match[2]];
    }
}
