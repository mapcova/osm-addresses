<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Sources;

use MapCova\OsmAddresses\Build\SourceFormatException;

/**
 * An open address dataset the builder knows how to turn into a database.
 */
interface Source
{
    /** ISO 3166-1 alpha-2: `GB`, `IE`. */
    public function country(): string;

    /** `osm`. */
    public function id(): string;

    public function name(): string;

    public function url(): string;

    public function licence(): string;

    public function licenceUrl(): string;

    public function attribution(): string;

    /** Compressed size of the whole source, used before anything has been downloaded. */
    public function approxBytes(): int;

    /** Compressed size of the largest chunk. */
    public function largestChunkBytes(): int;

    /**
     * Seconds a build takes on a slow machine (the measured laptop time, times 3).
     */
    public function estimatedBuildSeconds(): int;

    /** Database size over compressed source size (measured). */
    public function finalRatio(): float;

    /** Working database size over the largest chunk's compressed size (measured). */
    public function stagingRatio(): float;

    /** Buckets a chunk is finalised in; bounds the duration of one finalisation. */
    public function bucketCount(): int;

    /** Whether the database gets a `trigram` index (compound words). */
    public function hasTrigram(): bool;

    /**
     * Whether the addresses of a street with the same number and suffix are one address
     * (the boxes of a building share its point): the first one read is kept.
     */
    public function dedupeHouse(): bool;

    /**
     * SQL expression of the commune a `$column` of commune codes belongs to, for the street rank
     * (the arrondissements of Paris, Lyon and Marseille are one town).
     */
    public function communeKeySql(string $column): string;

    /** @return list<Chunk> */
    public function chunks(): array;

    /** Cheap "has it changed?" signal: the Last-Modified and ETag of this URL. */
    public function versionUrl(): string;

    /**
     * Map the header of a chunk's CSV to column positions.
     *
     * @param list<string|null> $header
     *
     * @return array<string,int>
     *
     * @throws SourceFormatException when an expected column is missing
     */
    public function columns(array $header): array;

    /**
     * One CSV record as an address, `null` when it is to be rejected
     * (no street name, a position outside the country).
     *
     * @param list<string|null>  $fields
     * @param array<string,int>  $columns
     */
    public function row(array $fields, array $columns): ?AddressRow;
}
