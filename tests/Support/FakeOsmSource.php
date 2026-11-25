<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Support;

use MapCova\OsmAddresses\Build\RecordReader;
use MapCova\OsmAddresses\Build\SourceFormatException;
use MapCova\OsmAddresses\Build\TsvRecordReader;
use MapCova\OsmAddresses\Osm\OsmExtractor;
use MapCova\OsmAddresses\Osm\PlaceIndex;
use MapCova\OsmAddresses\Sources\AddressRow;
use MapCova\OsmAddresses\Sources\Chunk;
use MapCova\OsmAddresses\Sources\ExtractSource;

/**
 * A test-only source over an OpenStreetMap extract: the engine of the extraction phase without any country's rules. The city
 * of an address that has none is the nearest place of the extract.
 */
final class FakeOsmSource implements ExtractSource
{
    private ?PlaceIndex $places = null;

    public function country(): string
    {
        return 'GB';
    }

    public function id(): string
    {
        return 'fake-osm';
    }

    public function name(): string
    {
        return 'Fake OpenStreetMap extract';
    }

    public function url(): string
    {
        return 'https://download.fake-osm.test/';
    }

    public function licence(): string
    {
        return 'ODbL 1.0';
    }

    public function licenceUrl(): string
    {
        return 'https://opendatacommons.org/licenses/odbl/1-0/';
    }

    public function attribution(): string
    {
        return '© OpenStreetMap contributors';
    }

    public function approxBytes(): int
    {
        return 4000;
    }

    public function largestChunkBytes(): int
    {
        return 4000;
    }

    public function estimatedBuildSeconds(): int
    {
        return 1;
    }

    public function finalRatio(): float
    {
        return 1.0;
    }

    public function stagingRatio(): float
    {
        return 1.0;
    }

    public function bucketCount(): int
    {
        return 2;
    }

    public function hasTrigram(): bool
    {
        return false;
    }

    public function dedupeHouse(): bool
    {
        return false;
    }

    public function communeKeySql(string $column): string
    {
        return $column;
    }

    public function chunks(): array
    {
        return [new Chunk('osm', OsmExtractFixtures::URL, Chunk::PLAIN)];
    }

    public function versionUrl(): string
    {
        return OsmExtractFixtures::URL;
    }

    public function languages(): array
    {
        return ['cy'];
    }

    public function reader(string $path, Chunk $chunk): RecordReader
    {
        $this->places = PlaceIndex::fromFile(OsmExtractor::placesPath($path));

        return new TsvRecordReader(OsmExtractor::addressesPath($path));
    }

    public function columns(array $header): array
    {
        $columns = array_flip(array_map(static fn (?string $name): string => (string) $name, $header));

        foreach (['lat_e6', 'lon_e6', 'number', 'housename', 'street', 'place', 'city'] as $name) {
            if (!isset($columns[$name])) {
                throw new SourceFormatException('err_ban_column', [$name]);
            }
        }

        return $columns;
    }

    public function row(array $fields, array $columns): ?AddressRow
    {
        $field = static fn (string $name): string => trim((string) ($fields[$columns[$name]] ?? ''));
        $lat = (int) $field('lat_e6');
        $lon = (int) $field('lon_e6');
        $street = $field('street') !== '' ? $field('street') : $field('place');
        $number = $field('number');
        $suffix = null;

        if ($number === '') {
            $street = $field('housename') . ', ' . $street;
            $number = null;
        } elseif (preg_match('/^(\d+)([a-z]|[-\/]\d+)?$/', $number, $match) === 1) {
            $number = $match[1];
            $suffix = ($match[2] ?? '') === '' ? null : $match[2];
        } else {
            return null;
        }

        $city = $field('city');

        if ($city === '' && $this->places !== null) {
            $city = $this->places->nearest($lat, $lon)['name'] ?? '';
        }

        if ($city === '') {
            return null;
        }

        return new AddressRow($street, null, $city, mb_strtolower($city), null, $number, $suffix, $lat, $lon);
    }
}
