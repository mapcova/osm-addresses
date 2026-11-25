<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Sources;

use MapCova\OsmAddresses\Build\RecordReader;
use MapCova\OsmAddresses\Build\SourceFormatException;
use MapCova\OsmAddresses\Build\TsvRecordReader;
use MapCova\OsmAddresses\Osm\AddressTags;
use MapCova\OsmAddresses\Osm\IrelandBorder;
use MapCova\OsmAddresses\Osm\OsmExtractor;
use MapCova\OsmAddresses\Osm\PlaceIndex;
use MapCova\OsmAddresses\Text\TextNormalizer;

/**
 * The addresses of an OpenStreetMap extract (ODbL 1.0), as the extraction phase writes them (`OsmExtractor`, columns of
 * {@see AddressTags::HEADER}). What a country adds is its extract, its postcodes, its side of the border and its languages.
 *
 * The rules:
 *
 * - Numbers: `10`, `10a` (suffix `a`), `2-6` and `12/14` (number 2, suffix `-6`; number 12, suffix `/14`). A house with a name and
 *   no number is a street of its own, `"<house name>, <street>"`, with one address and no number. The street is `addr:street`,
 *   else `addr:place` (a rural address).
 * - City: `addr:city`, else `addr:town` (both in the extracted column), else the most frequent city of the address's outward
 *   code (British postcodes follow post towns), else the nearest place of the extract within the radius of its type
 *   ({@see PlaceIndex::RADIUS}); an address with none is rejected. District: `addr:suburb`, else `addr:village` or `addr:hamlet`,
 *   when it is not the city.
 * - Postcodes: the street holds the first half, the address the second ({@see self::postcode()}), the street's postcode being shared by all its addresses.
 * - Other languages: the street names `name:<language>` of the extract's highways, found near the address by name, and those of
 *   the city's place node, go to `names` (display) and to `extra` (search). The name without its apostrophes (`St Johns Road`
 *   for `St John's Road`, which the normaliser reads as `st john s road`) goes to `extra` too, for the people who type it so.
 * - No trigram index: English and Irish street names are not compound words. Addresses with the same street, number and suffix
 *   are one (a shop and its building): the first one read stays.
 *
 * The OpenStreetMap data stay in their own databases: never fill another country's gaps with them (ODbL 4.4).
 */
abstract class OsmSource implements ExtractSource
{
    public const LICENCE_URL = 'https://opendatacommons.org/licenses/odbl/1-0/';

    /** Metres within which a place's name forms (`Llundain`) are those of an address's city. */
    private const CITY_FORM_METRES = 30_000;

    private const CELL_E6 = 50_000;

    private const APOSTROPHES = ["'", "\u{2019}", "\u{2018}", "\u{02BC}", '`'];

    private static ?IrelandBorder $border = null;

    private ?PlaceIndex $places = null;

    /** @var array<string,string> outward code => its most frequent city */
    private array $postCities = [];

    /** @var array<string,list<array{int,int,string,array<string,string>}>> normalised name of a place (or of one of its forms) => lat, lon, name, forms by language */
    private array $placeForms = [];

    /** @var array<int,array<string,array{string,array<string,string>}>> cell => normalised name of a street (or of one of its forms) => name, forms by language */
    private array $streetForms = [];

    /** @var array<string,int> rows dropped by reason and what the chain fell back on, since this object was made (a resumed build counts per tick) */
    private array $tallies = [];

    /** Whether a position belongs to this country (its box, and its side of the border). */
    abstract protected function accepts(int $latE6, int $lonE6): bool;

    /**
     * The street's postcode and the address's from the text of `addr:postcode`: `[null, null]` for none or an invalid one.
     *
     * @return array{?string,?string}
     */
    abstract protected function postcode(string $text): array;

    /** Whether the most frequent city of an address's outward code names an address without city (British post towns). */
    abstract protected function citiesFollowPostcodes(): bool;

    /**
     * Whether the extract's `name` is in one of the source's languages (Ireland: Irish or English, by area), so that a name that is
     * not the address's own is its form in the other language.
     */
    protected function namesAreForms(): bool
    {
        return false;
    }

    /** The border, read once. */
    protected static function border(): IrelandBorder
    {
        return self::$border ??= IrelandBorder::load();
    }

    public function id(): string
    {
        return 'osm';
    }

    public function licence(): string
    {
        return 'ODbL 1.0';
    }

    public function licenceUrl(): string
    {
        return self::LICENCE_URL;
    }

    public function attribution(): string
    {
        return '© OpenStreetMap contributors, ODbL 1.0 — https://www.openstreetmap.org/copyright';
    }

    public function hasTrigram(): bool
    {
        return false;
    }

    public function dedupeHouse(): bool
    {
        return true;
    }

    public function communeKeySql(string $column): string
    {
        return $column;
    }

    public function versionUrl(): string
    {
        return $this->chunks()[0]->url;
    }

    /**
     * Rows dropped by reason (`position`, `no_name`, `bad_number`, `no_street`, `no_city`, `text`) and how the numbered ones
     * went (`numbered`, `no_postcode`, `bad_postcode`, `city_from_postcode`, `city_from_place`), since this object was made.
     *
     * @return array<string,int>
     */
    public function tallies(): array
    {
        return $this->tallies;
    }

    public function reader(string $path, Chunk $chunk): RecordReader
    {
        $this->load($path);

        return new TsvRecordReader(OsmExtractor::addressesPath($path));
    }

    /**
     * Read what the extraction left next to the chunk: the places, the postcode-to-city table and the streets with a name in
     * another language. A file that is not there is empty.
     */
    public function load(string $chunkPath): void
    {
        $this->places = new PlaceIndex();
        $this->postCities = [];
        $this->placeForms = [];
        $this->streetForms = [];

        foreach (self::records(OsmExtractor::placesPath($chunkPath)) as $header => $record) {
            $languages = array_slice($header, 4);
            $forms = self::forms($languages, array_slice($record, 4));
            [$type, $name, $lat, $lon] = [$record[0], $record[1] ?? '', (int) ($record[2] ?? 0), (int) ($record[3] ?? 0)];
            $this->places->add($type, $name, $lat, $lon, $forms);

            foreach ([$name, ...array_values($forms)] as $text) {
                $this->placeForms[TextNormalizer::normalize($text)][] = [$lat, $lon, $name, $forms];
            }
        }

        foreach (self::records(OsmExtractor::postcityPath($chunkPath)) as $record) {
            $this->postCities[$record[0]] = $record[1] ?? '';
        }

        foreach (self::records(OsmExtractor::namesPath($chunkPath)) as $header => $record) {
            $name = $record[0];
            $forms = self::forms(array_slice($header, 3), array_slice($record, 3));
            $cell = self::cell((int) ($record[1] ?? 0), (int) ($record[2] ?? 0));

            foreach ([$name, ...array_values($forms)] as $text) {
                $this->streetForms[$cell][TextNormalizer::normalize($text)] ??= [$name, $forms];
            }
        }
    }

    public function columns(array $header): array
    {
        $columns = array_flip(array_map(static fn (?string $name): string => (string) $name, $header));

        foreach (AddressTags::HEADER as $name) {
            if (!isset($columns[$name])) {
                throw new SourceFormatException('err_osm_column', [$name]);
            }
        }

        return $columns;
    }

    public function row(array $fields, array $columns): ?AddressRow
    {
        $field = static fn (string $name): string => trim((string) ($fields[$columns[$name]] ?? ''));
        $lat = (int) $field('lat_e6');
        $lon = (int) $field('lon_e6');

        if (!$this->accepts($lat, $lon)) {
            return $this->reject('position');
        }

        $street = $field('street') !== '' ? $field('street') : $field('place');
        $number = null;
        $suffix = null;
        $prefix = '';

        if ($field('number') === '') {
            if ($field('housename') === '' || $street === '') {
                return $this->reject('no_name');
            }

            $prefix = $field('housename') . ', ';
        } elseif (preg_match('/^(\d{1,5})([a-z]|[-\/]\d{1,5})?$/', $field('number'), $match) === 1 && ($number = ltrim($match[1], '0')) !== '') {
            $suffix = ($match[2] ?? '') === '' ? null : $match[2];
        } else {
            return $this->reject('bad_number');
        }

        if ($street === '') {
            return $this->reject('no_street');
        }

        [$outward, $pc] = $field('postcode') === '' ? [null, null] : $this->postcode($field('postcode'));

        if ($field('postcode') !== '' && $outward === null) {
            $this->count('bad_postcode');
        }

        $city = $field('city');

        if ($number !== null) {
            $this->count('numbered');

            if ($outward === null) {
                $this->count('no_postcode');
            }
        }

        if ($city === '' && $outward !== null && $this->citiesFollowPostcodes()) {
            $city = $this->postCities[$outward] ?? '';

            if ($city !== '' && $number !== null) {
                $this->count('city_from_postcode');
            }
        }

        if ($city === '' && $this->places !== null) {
            $city = $this->places->nearest($lat, $lon)['name'] ?? '';

            if ($city !== '' && $number !== null) {
                $this->count('city_from_place');
            }
        }

        if ($city === '') {
            return $this->reject('no_city');
        }

        $district = null;

        foreach (['suburb', 'village', 'hamlet'] as $column) {
            if ($field($column) !== '' && mb_strtolower($field($column)) !== mb_strtolower($city)) {
                $district = $field($column);

                break;
            }
        }

        $name = $prefix . $street;
        $names = [];
        $extra = [];

        foreach ($this->otherForms($street, $city, $lat, $lon) as $language => [$streetForm, $cityForm]) {
            $names[$language] = [$prefix . $streetForm, $cityForm];
            $extra[] = $prefix . $streetForm;
            $extra[] = $cityForm;
        }

        // What the normaliser would split ("St John's" is "st john s"): the name without its apostrophes is searchable too.
        foreach ([$name, $city, ...$extra] as $text) {
            $extra[] = str_replace(self::APOSTROPHES, '', $text);
        }

        $extra = array_values(array_unique(array_filter($extra, static fn (string $text): bool => $text !== '' && $text !== $name && $text !== $city)));

        if (!RowGuard::text($name, $city, (string) $district, (string) $outward, (string) $pc, ...$extra)) {
            return $this->reject('text');
        }

        return new AddressRow(
            $name,
            $outward,
            $city,
            TextNormalizer::normalize($city),
            $district,
            $number,
            $suffix,
            $lat,
            $lon,
            implode(' ', $extra),
            $names === [] ? null : json_encode($names, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $pc,
        );
    }

    /**
     * The street and the city in each language that has a form of either different from the address's own. Where the extract's
     * `name` is itself in one of the languages ({@see self::namesAreForms()}) and the address wrote the other form (`Beach Street`
     * for a way named `Sráid na Trá`), the name is the form of the language that has no tag of its own.
     *
     * @return array<string,array{string,string}>
     */
    private function otherForms(string $street, string $city, int $lat, int $lon): array
    {
        $road = $this->street($street, $lat, $lon);
        $place = $this->place($city, $lat, $lon);
        $out = [];

        foreach ($this->languages() as $language) {
            $form = [
                $road[1][$language] ?? ($this->namesAreForms() && $road !== null && self::differs($road[0], $street) ? $road[0] : $street),
                $place[3][$language] ?? ($this->namesAreForms() && $place !== null && self::differs($place[2], $city) ? $place[2] : $city),
            ];

            if ($form !== [$street, $city]) {
                $out[$language] = $form;
            }
        }

        return $out;
    }

    private static function differs(string $a, string $b): bool
    {
        return TextNormalizer::normalize($a) !== TextNormalizer::normalize($b);
    }

    /**
     * The street of the extract that has this name and is the nearest to the position (its own cell and the eight around).
     *
     * @return array{string,array<string,string>}|null name, forms by language
     */
    private function street(string $street, int $lat, int $lon): ?array
    {
        $key = TextNormalizer::normalize($street);
        $row = intdiv($lat + 90_000_000, self::CELL_E6);
        $column = intdiv($lon + 180_000_000, self::CELL_E6);

        for ($r = $row - 1; $r <= $row + 1; ++$r) {
            for ($c = $column - 1; $c <= $column + 1; ++$c) {
                if (isset($this->streetForms[$r * 100_000 + $c][$key])) {
                    return $this->streetForms[$r * 100_000 + $c][$key];
                }
            }
        }

        return null;
    }

    /**
     * The nearest place of the extract that has this name.
     *
     * @return array{int,int,string,array<string,string>}|null
     */
    private function place(string $city, int $lat, int $lon): ?array
    {
        $best = null;
        $near = (float) self::CITY_FORM_METRES;
        $cosine = max(0.01, cos(deg2rad($lat / 1e6)));

        foreach ($this->placeForms[TextNormalizer::normalize($city)] ?? [] as $place) {
            $metres = hypot(($place[0] - $lat) / 1e6 * 111_320.0, ($place[1] - $lon) / 1e6 * 111_320.0 * $cosine);

            if ($metres <= $near) {
                [$best, $near] = [$place, $metres];
            }
        }

        return $best;
    }

    private function reject(string $reason): null
    {
        $this->count($reason);

        return null;
    }

    private function count(string $reason): void
    {
        $this->tallies[$reason] = ($this->tallies[$reason] ?? 0) + 1;
    }

    private static function cell(int $lat, int $lon): int
    {
        return intdiv($lat + 90_000_000, self::CELL_E6) * 100_000 + intdiv($lon + 180_000_000, self::CELL_E6);
    }

    /**
     * The language forms of a record, the ones it has.
     *
     * @param list<string|null> $languages
     * @param list<string>      $values
     *
     * @return array<string,string>
     */
    private static function forms(array $languages, array $values): array
    {
        $forms = [];

        foreach ($languages as $position => $language) {
            if (($values[$position] ?? '') !== '') {
                $forms[(string) $language] = $values[$position];
            }
        }

        return $forms;
    }

    /**
     * The records of an extracted file; the key is the header (for the language columns).
     *
     * @return \Generator<list<string|null>,list<string>>
     */
    private static function records(string $path): \Generator
    {
        if (!is_file($path)) {
            return;
        }

        $reader = new TsvRecordReader($path);
        $header = $reader->header() ?? [];

        while (($record = $reader->next()) !== null) {
            yield $header => array_map(static fn (?string $value): string => (string) $value, $record);
        }
    }
}
