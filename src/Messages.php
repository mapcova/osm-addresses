<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses;

/**
 * English text of the error codes the builder and the sources raise (`messages.en.json`). The build
 * reports the code, its parameters and this text; nobody translates them (they are for the operator).
 */
final class Messages
{
    /** @var array<string,string>|null */
    private static ?array $english = null;

    private function __construct()
    {
    }

    /** @param list<string|int|float> $params */
    public static function english(string $key, array $params = []): string
    {
        self::$english ??= self::load();
        $text = self::$english[$key] ?? $key;

        foreach ($params as $index => $value) {
            $text = str_replace('{' . $index . '}', (string) $value, $text);
        }

        return $text;
    }

    /** @return array<string,string> */
    private static function load(): array
    {
        $raw = @file_get_contents(__DIR__ . '/messages.en.json');
        $decoded = $raw === false ? null : json_decode($raw, true);

        return is_array($decoded) ? array_filter($decoded, 'is_string') : [];
    }
}
