<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Build;

/**
 * What the builder needs to know about the time it has left, nothing more:
 * a caller that slices a build adapts its own budget to this.
 */
interface BuildBudget
{
    /** Whether `$seconds` of work still fit. */
    public function canAfford(float $seconds): bool;

    /** Whether the caller asked the build to stop (pause, cancel). */
    public function shouldStop(): bool;
}
