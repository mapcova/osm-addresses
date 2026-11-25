<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Support;

/**
 * Marker for an exception that no retry can fix (the pinned source moved, a source in a format we do not know).
 */
interface PermanentFailure
{
}
