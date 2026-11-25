<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Build;

use MapCova\OsmAddresses\Messages;
use MapCova\OsmAddresses\Support\PermanentFailure;
use RuntimeException;

/**
 * A build failure no retry can fix (a source in a format we do not know, a
 * full disk, a truncated source): the caller stops at once and shows the
 * message, which names the cause and never a typed text or a position.
 *
 * Built from a key of `messages.en.json` and its parameters: the English text is
 * the message, the key and parameters are what a caller records.
 */
class PermanentBuildException extends RuntimeException implements PermanentFailure
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
