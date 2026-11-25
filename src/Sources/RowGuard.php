<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Sources;

/**
 * What a source row must satisfy before it reaches a database: a real
 * position, and text that is UTF-8 and of the size of a name.
 */
final class RowGuard
{
    public const MAX_FIELD_BYTES = 200;

    private function __construct()
    {
    }

    public static function finite(float $lat, float $lon): bool
    {
        return is_finite($lat) && is_finite($lon);
    }

    public static function text(string ...$fields): bool
    {
        foreach ($fields as $field) {
            if (strlen($field) > self::MAX_FIELD_BYTES || !mb_check_encoding($field, 'UTF-8')) {
                return false;
            }
        }

        return true;
    }
}
