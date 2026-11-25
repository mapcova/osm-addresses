<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Build;

use MapCova\OsmAddresses\Messages;
use RuntimeException;

/**
 * A build failure worth retrying (a damaged download, a file that vanished
 * between two steps), with the same key and parameters as
 * {@see PermanentBuildException}.
 */
final class TransientBuildException extends RuntimeException
{
    /**
     * @param list<string|int|float> $params
     */
    public function __construct(private readonly string $key, private readonly array $params = [])
    {
        parent::__construct(Messages::english($key, $params));
    }

    public function key(): string
    {
        return $this->key;
    }

    /** @return list<string|int|float> */
    public function params(): array
    {
        return $this->params;
    }
}
