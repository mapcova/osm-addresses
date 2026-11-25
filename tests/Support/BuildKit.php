<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Support;

use Closure;
use MapCova\OsmAddresses\Build\BuildState;
use MapCova\OsmAddresses\Build\DatasetBuilder;
use MapCova\OsmAddresses\Sources\ExtractSource;

/**
 * Builds a database from the fixtures, slice by slice, and dumps it.
 */
final class BuildKit
{
    private function __construct()
    {
    }

    /**
     * Run a build to the end. `$extraUnits` per call: `0` is a resume after every unit.
     *
     * @param (Closure(BuildState): void)|null $afterCheckpoint called after each checkpoint
     *
     * @return array{BuildState, int} the final state and the number of calls to `run()`
     */
    public static function build(ExtractSource $source, RangeHttp $http, string $dataDir, int $extraUnits, string $buildId = '20261118-000001', ?Closure $afterCheckpoint = null, ?Closure $freeBytes = null, int $extractBlobs = 256): array
    {
        $builder = new DatasetBuilder($source, $http, $dataDir, $freeBytes, null, 64, 7, 3, 2, $extractBlobs);
        $state = DatasetBuilder::newState($source, $buildId);
        $runs = 0;

        while (!$state->isDone()) {
            $state = BuildState::fromArray($state->toArray());
            $state = $builder->run($state, new UnitBudget($extraUnits), static function (BuildState $s) use ($afterCheckpoint): void {
                if ($afterCheckpoint !== null) {
                    $afterCheckpoint($s);
                }
            });
            ++$runs;

            if ($runs > 5000) {
                throw new \RuntimeException('The build does not end.');
            }
        }

        return [$state, $runs];
    }

    /**
     * Everything that identifies the content of a database, without what
     * differs between two builds of the same data (time, build id).
     *
     * @return array<string,mixed>
     */
    public static function dump(string $path): array
    {
        $db = Sql::open($path);
        $all = static fn (string $sql): array => Sql::rows($db, $sql);
        $meta = array_column($all('SELECT key, value FROM meta ORDER BY key'), 'value', 'key');
        unset($meta['built_at'], $meta['build_id'], $meta['source_updated_at']);

        $fts = [];

        foreach (['rue', 'rivoli', 'bundesplatz', 'hauptstrasse', 'marseille', 'saint', '75001', 'zurich'] as $word) {
            $fts[$word] = Sql::column($db, sprintf("SELECT rowid FROM street_fts WHERE street_fts MATCH '\"%s\"*' ORDER BY rowid", $word));
        }

        return [
            'meta' => $meta,
            'street' => $all('SELECT * FROM street ORDER BY id'),
            'address' => $all('SELECT * FROM address ORDER BY id'),
            'rtree' => $all('SELECT * FROM street_rtree ORDER BY id'),
            'fts' => $fts,
            'tables' => array_values(array_filter(
                array_column($all("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"), 'name'),
                static fn (string $name): bool => preg_match('/^(street_(fts|tri|rtree)|sqlite_)/', $name) !== 1,
            )),
        ];
    }

    /**
     * @param array<string,string> $files url => body
     */
    public static function serve(array $files): RangeHttp
    {
        $http = new RangeHttp();

        foreach ($files as $url => $body) {
            $http->serve($url, $body);
        }

        return $http;
    }
}
