<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Build;

use MapCova\OsmAddresses\Sources\Source;

/**
 * How much disk a build takes. The ratios are the measured ones of each
 * source ({@see Source::finalRatio()}); the old database counts until the
 * new one replaces it.
 */
final class SpaceEstimator
{
    public const MARGIN = 1.1;

    /**
     * @param ?int   $downloadBytes   compressed size of the source, `null` for the source's own estimate
     * @param int    $existingBytes   size of the database being replaced
     * @param ?float $freeBytes       free space, `null` when unknown
     * @param ?int   $quotaLeftBytes  room left under the data quota, `null` for no quota
     * @param ?int   $largestProbed   size of the largest file as measured by the probes, `null` for the source's own estimate
     */
    public static function estimate(Source $source, ?int $downloadBytes, int $existingBytes, ?float $freeBytes, ?int $quotaLeftBytes = null, ?int $largestProbed = null): SpaceEstimate
    {
        $download = $downloadBytes ?? $source->approxBytes();
        $final = (int) round($download * $source->finalRatio());
        $largest = min($download, $largestProbed ?? $source->largestChunkBytes());
        $staging = (int) round($largest * $source->stagingRatio());
        $peak = (int) round(($final + $staging + $largest + $existingBytes) * self::MARGIN);

        return new SpaceEstimate($download, $final, $peak, $existingBytes, $freeBytes, $quotaLeftBytes);
    }
}
