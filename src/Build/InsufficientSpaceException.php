<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Build;

/**
 * The disk, or the data quota, cannot hold the build.
 */
final class InsufficientSpaceException extends PermanentBuildException
{
}
