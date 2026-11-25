<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Osm;

/**
 * What an OpenStreetMap object must say to be an address, and how the extraction writes it.
 *
 * An object is an address when it has a house number or a house name, and a street or a place. A house number is one
 * of `10`, `10a`, `2-6`, `12/14`, or a list of them (`2;4;6`, `2,4,6`, at most {@see self::MAX_NUMBERS}): each
 * number of a list becomes an address at the same point. A number that is none of these (`Flat 2`) is dropped; a
 * house with a name and no usable number stays as a named house.
 */
final class AddressTags
{
    public const MAX_NUMBERS = 10;

    /** The columns of the extracted addresses. `city` is `addr:city`, else `addr:town`. */
    public const HEADER = ['id', 'lat_e6', 'lon_e6', 'number', 'housename', 'street', 'place', 'postcode', 'city', 'suburb', 'village', 'hamlet'];

    /** The tags the extraction reads. */
    public const KEYS = ['addr:housenumber', 'addr:housename', 'addr:street', 'addr:place', 'addr:postcode', 'addr:city', 'addr:town', 'addr:suburb', 'addr:village', 'addr:hamlet'];

    /**
     * The address of an object's tags: its numbers (`['']` for a named house) and the text columns after the number.
     *
     * @param array<string,string> $tags
     *
     * @return array{numbers:list<string>,rest:list<string>}|string the reason it is not an address (`no_street`, `bad_number`)
     */
    public static function read(array $tags): array|string
    {
        $housenumber = self::clean($tags['addr:housenumber'] ?? '');
        $housename = self::clean($tags['addr:housename'] ?? '');
        $street = self::clean($tags['addr:street'] ?? '');
        $place = self::clean($tags['addr:place'] ?? '');

        if ($street === '' && $place === '') {
            return 'no_street';
        }

        $numbers = $housenumber === '' ? [] : self::numbers($housenumber);

        if ($numbers === []) {
            if ($housename === '') {
                return 'bad_number';
            }

            $numbers = [''];
        }

        return [
            'numbers' => $numbers,
            'rest' => [
                $housename,
                $street,
                $place,
                self::clean($tags['addr:postcode'] ?? ''),
                self::clean($tags['addr:city'] ?? '') ?: self::clean($tags['addr:town'] ?? ''),
                self::clean($tags['addr:suburb'] ?? ''),
                self::clean($tags['addr:village'] ?? ''),
                self::clean($tags['addr:hamlet'] ?? ''),
            ],
        ];
    }

    /** @return list<string> the usable numbers of a `addr:housenumber`, in lower case; none when it holds none */
    public static function numbers(string $value): array
    {
        $out = [];

        foreach (preg_split('/[;,]/', $value) ?: [] as $item) {
            $item = strtolower(preg_replace('/\s*([-\/])\s*/', '$1', trim($item)) ?? '');

            if (preg_match('/^\d{1,5}[a-z]?$|^\d{1,5}[-\/]\d{1,5}$/', $item) === 1 && !in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return array_slice($out, 0, self::MAX_NUMBERS);
    }

    /** Tabs and line breaks become spaces: a value never breaks the line of a record. */
    public static function clean(string $value): string
    {
        return trim(preg_replace('/[\t\r\n]+/', ' ', $value) ?? '');
    }

    /** The outward code of a British postcode (`SW1A` of `SW1A 1AA`), or null. */
    public static function outward(string $postcode): ?string
    {
        return preg_match('/^([A-Z]{1,2}\d[A-Z\d]?)\s*\d[A-Z]{2}$/', strtoupper(trim($postcode)), $match) === 1 ? $match[1] : null;
    }
}
