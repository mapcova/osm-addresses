#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds the database of the United Kingdom or Ireland from a local OpenStreetMap extract, the way the command line does, but in
 * slices (all the phases of the DatasetBuilder), and prints what a build costs: time per phase, peak memory, peak disk, rows,
 * rejections by reason, the share of numbered addresses without a postcode or a city, the duplicated street rows.
 *
 *   php tools/osm-measure.php --country GB|IE --pbf <file> [--slice <seconds>] [--blobs <per unit>] [--dir <work folder>]
 *
 * `--slice` is the budget of a slice in the builder's own seconds (default 240), the build is
 * resumed from its saved state after each one. The extract is served to the builder by a client that reads the file; no network is used. The work folder (default: the system temporary folder) holds
 * the build and the final database, and is the caller's to delete.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use MapCova\OsmAddresses\Build\BuildBudget;
use MapCova\OsmAddresses\Build\BuildState;
use MapCova\OsmAddresses\Build\DatasetBuilder;
use MapCova\OsmAddresses\Sources\GreatBritainSource;
use MapCova\OsmAddresses\Sources\IrelandSource;
use MapCova\OsmAddresses\Sources\OsmSource;
use MapCova\OsmAddresses\Support\FileHttpClient;

$options = getopt('', ['country:', 'pbf:', 'slice:', 'blobs:', 'dir:']);
$country = strtoupper((string) ($options['country'] ?? ''));
$pbf = (string) ($options['pbf'] ?? '');

if (!in_array($country, ['GB', 'IE'], true) || !is_file($pbf)) {
    fwrite(STDERR, "usage: php tools/osm-measure.php --country GB|IE --pbf <file> [--slice <seconds>] [--blobs <per unit>] [--dir <work folder>]\n");
    exit(2);
}

$slice = (float) ($options['slice'] ?? 240);
$blobs = (int) ($options['blobs'] ?? 256);
$dir = (string) ($options['dir'] ?? sys_get_temp_dir()) . '/osm-measure-' . bin2hex(random_bytes(8));

if (!mkdir($dir, 0o700, true)) {
    fwrite(STDERR, "cannot create the work folder $dir\n");
    exit(1);
}

$source = $country === 'GB' ? new GreatBritainSource() : new IrelandSource();
$size = (int) filesize($pbf);
$http = new FileHttpClient($pbf, $source->versionUrl());

$budget = new class ($slice) implements BuildBudget {
    public float $left;

    public function __construct(private readonly float $slice)
    {
        $this->left = $slice;
    }

    public function refill(): void
    {
        $this->left = $this->slice;
    }

    public function canAfford(float $seconds): bool
    {
        if ($this->left < $seconds) {
            return false;
        }

        $this->left -= $seconds;

        return true;
    }

    public function shouldStop(): bool
    {
        return false;
    }
};

function folderBytes(string $dir): int
{
    $bytes = 0;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $bytes += $file->getSize();
    }

    return $bytes;
}

function mb(int|float $bytes): string
{
    return number_format($bytes / 1_048_576, 1) . ' MB';
}

$builder = new DatasetBuilder($source, $http, $dir, null, null, 32 << 20, 50_000, 50_000, 100_000, $blobs);
$state = DatasetBuilder::newState($source, '20261125-000001');
$seconds = [];
$peakDisk = 0;
$peakPhase = '';
$slices = 0;
$stats = [];
$steps = [];
$previous = 'verify';
$began = microtime(true);
$mark = $began;
$last = $state->phase;

while (!$state->isDone()) {
    $state = BuildState::fromArray($state->toArray());
    $budget->refill();
    $state = $builder->run($state, $budget, function (BuildState $s) use ($dir, &$seconds, &$peakDisk, &$peakPhase, &$mark, &$stats, &$steps, &$previous): void {
        $now = microtime(true);
        $seconds[$s->phase] = ($seconds[$s->phase] ?? 0.0) + $now - $mark;
        $elapsed = $now - $mark;
        $mark = $now;
        $bytes = folderBytes($dir);

        if ($bytes > $peakDisk) {
            [$peakDisk, $peakPhase] = [$bytes, $s->phase];
        }

        // The work folder is new for each run, so it holds one build and at most one extraction state.
        $file = (glob($dir . '/.build/*.chunk.osm.json') ?: [])[0] ?? null;
        $saved = $file === null ? null : json_decode((string) file_get_contents($file), true);

        if (\is_array($saved)) {
            $stats = \is_array($saved['stats'] ?? null) ? $saved['stats'] : $stats;

            // The unit that just ended belonged to the step the file held before it.
            if ($s->phase === BuildState::EXTRACT) {
                $steps[$previous] = ($steps[$previous] ?? 0.0) + $elapsed;
                $previous = (string) ($saved['step'] ?? $previous);
            }
        }
    });
    ++$slices;
}

$total = microtime(true) - $began;
$db = new PDO('sqlite:' . DatasetBuilder::finalPath($dir, $country));
$one = static fn (string $sql): mixed => $db->query($sql)->fetchColumn();
$meta = $db->query('SELECT key, value FROM meta')->fetchAll(PDO::FETCH_KEY_PAIR);
$tallies = $source instanceof OsmSource ? $source->tallies() : [];
$streets = (int) $one('SELECT COUNT(*) FROM street');
$split = (int) $one('SELECT COUNT(*) FROM (SELECT name, city_code FROM street GROUP BY name, city_code HAVING SUM(postcode IS NULL) > 0 AND SUM(postcode IS NOT NULL) > 0)');
$distinct = (int) $one('SELECT COUNT(*) FROM (SELECT 1 FROM street GROUP BY name, city_code)');
$numbered = (int) ($tallies['numbered'] ?? 0);
$final = (int) filesize(DatasetBuilder::finalPath($dir, $country));

printf("%s from %s (%s)\n", $country, basename($pbf), mb($size));
printf("slices of %.0f s: %d; wall %.1f s; peak memory %s\n", $slice, $slices, $total, mb(memory_get_peak_usage(true)));
printf("seconds per phase (wall): %s\n", implode(', ', array_map(static fn (string $p, float $s): string => sprintf('%s %.1f', $p, $s), array_keys($seconds), $seconds)));
printf("seconds per extraction step (wall): %s\n", implode(', ', array_map(static fn (string $p, float $s): string => sprintf('%s %.1f', $p, $s), array_keys($steps), $steps)));
printf("peak disk of the work folder: %s at the end of %s (final database %s, ratio %.3f of the extract)\n", mb($peakDisk), $peakPhase, mb($final), $final / $size);
printf("peak disk over extract: %.2f\n", $peakDisk / $size);
printf("rows %s, streets %d (%d distinct names per city), rejected at ingest %s\n", $meta['rows'] ?? '?', $streets, $distinct, $meta['rejected'] ?? '?');
printf("extraction counts: %s\n", json_encode($stats));
printf("source tallies: %s\n", json_encode($tallies));

if ($numbered > 0) {
    printf("numbered addresses without a postcode: %.1f %%; city from the postcode's post town: %.1f %%, from the nearest place: %.1f %%\n", 100 * ($tallies['no_postcode'] ?? 0) / $numbered, 100 * ($tallies['city_from_postcode'] ?? 0) / $numbered, 100 * ($tallies['city_from_place'] ?? 0) / $numbered);
}

printf("postcode fill: %d addresses given the key of their street, on %d (street, city) groups\n", $stats['postcode_filled'] ?? 0, $stats['fill_streets'] ?? 0);
printf("streets with and without postcode under one name and city: %d of %d (%.1f %%) (left split after the postcode fill)\n", $split, $distinct, $distinct === 0 ? 0 : 100 * $split / $distinct);
printf("database kept at %s\n", DatasetBuilder::finalPath($dir, $country));
