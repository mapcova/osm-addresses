#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds the address database of the United Kingdom (GB) or Ireland (IE) from a local OpenStreetMap extract.
 *
 *   php bin/build-osm-addresses.php --country GB|IE --pbf <file.osm.pbf> --out <file.sqlite> [--work <folder>]
 *
 * The extract is the Geofabrik one of the country (GB: united-kingdom, IE: ireland-and-northern-ireland), downloaded by hand.
 * Nothing is fetched. The build runs in a work folder (default: next to the output) that is deleted at the end; it needs about
 * the size of the extract in free disk space (GB: 3 GB of extract, about 1.3 times that again while it runs).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use MapCova\OsmAddresses\Build\BuildBudget;
use MapCova\OsmAddresses\Build\DatasetBuilder;
use MapCova\OsmAddresses\Sources\GreatBritainSource;
use MapCova\OsmAddresses\Sources\IrelandSource;
use MapCova\OsmAddresses\Support\FileHttpClient;

$options = getopt('', ['country:', 'pbf:', 'out:', 'work:']);
$option = static fn (string $name): string => is_string($options[$name] ?? null) ? $options[$name] : '';
$country = strtoupper($option('country'));
$pbf = $option('pbf');
$out = $option('out');

if (!in_array($country, ['GB', 'IE'], true) || !is_file($pbf) || $out === '') {
    fwrite(STDERR, "usage: php bin/build-osm-addresses.php --country GB|IE --pbf <file.osm.pbf> --out <file.sqlite> [--work <folder>]\n");
    exit(2);
}

$source = $country === 'GB' ? new GreatBritainSource() : new IrelandSource();
$work = ($option('work') !== '' ? $option('work') : dirname($out)) . '/osm-addresses-work-' . bin2hex(random_bytes(6));

if (!mkdir($work, 0o700, true)) {
    fwrite(STDERR, "cannot create the work folder $work\n");
    exit(1);
}

$unlimited = new class () implements BuildBudget {
    public function canAfford(float $seconds): bool
    {
        return true;
    }

    public function shouldStop(): bool
    {
        return false;
    }
};

$status = 0;

try {
    $builder = new DatasetBuilder($source, new FileHttpClient($pbf, $source->versionUrl()), $work, null, null, 32 << 20);
    $state = $builder->run(DatasetBuilder::newState($source, gmdate('Ymd-His')), $unlimited);

    if (!$state->isDone()) {
        throw new RuntimeException('the build did not finish');
    }

    $final = DatasetBuilder::finalPath($work, $country);

    if (!rename($final, $out) && !(copy($final, $out) && unlink($final))) {
        throw new RuntimeException("cannot write $out");
    }

    $db = new PDO('sqlite:' . $out);
    $rows = $db->query('SELECT key, value FROM meta');
    $meta = $rows === false ? [] : $rows->fetchAll(PDO::FETCH_KEY_PAIR);
    printf("%s: %s rows, %s streets, %s rejected -> %s\n", $country, $meta['rows'] ?? '?', $meta['streets'] ?? '?', $meta['rejected'] ?? '?', $out);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    $status = 1;
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($work);
}

exit($status);
