<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Support;

use MapCova\OsmAddresses\Build\BuildBudget;

/**
 * A budget of a number of units beyond the first one the builder always runs:
 * `0` makes every call resume after a single unit, the harshest slicing.
 */
final class UnitBudget implements BuildBudget
{
    public bool $stop = false;

    public function __construct(public int $extraUnits)
    {
    }

    public function canAfford(float $seconds): bool
    {
        if ($this->extraUnits <= 0) {
            return false;
        }

        --$this->extraUnits;

        return true;
    }

    public function shouldStop(): bool
    {
        return $this->stop;
    }
}
