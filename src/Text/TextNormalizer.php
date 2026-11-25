<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Text;

/**
 * The text normalisation of the databases, applied when a street is indexed (a reader applies the
 * same one to what it searches): lower case, no diacritics
 * (a table, not ext-intl), `ß` → `ss`, `œ` → `oe`, punctuation → space.
 */
final class TextNormalizer
{
    /** Version of the text rules: the same number as {@see \MapCova\OsmAddresses\TextRules::VERSION}. */
    public const RULES_VERSION = 1;

    /** @var array<string,string>|null */
    private static ?array $map = null;

    private function __construct()
    {
    }

    public static function normalize(string $text): string
    {
        // Most of a database is plain ASCII: skip the multibyte work for it.
        if (!preg_match('/[^\x00-\x7F]/', $text)) {
            return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($text)));
        }

        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, self::map());
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim($text);
    }

    /**
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $normalized = self::normalize($text);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /**
     * @return array<string,string>
     */
    private static function map(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        $groups = [
            'a' => 'àáâãäåāăą',
            'c' => 'çćĉċč',
            'd' => 'ďđ',
            'e' => 'èéêëēĕėęě',
            'g' => 'ĝğġģ',
            'h' => 'ĥħ',
            'i' => 'ìíîïĩīĭįı',
            'j' => 'ĵ',
            'k' => 'ķ',
            'l' => 'ĺļľŀł',
            'n' => 'ñńņňŉ',
            'o' => 'òóôõöøōŏő',
            'r' => 'ŕŗř',
            's' => 'śŝşš',
            't' => 'ţťŧ',
            'u' => 'ùúûüũūŭůűų',
            'w' => 'ŵ',
            'y' => 'ýÿŷ',
            'z' => 'źżž',
        ];
        $map = ['ß' => 'ss', 'œ' => 'oe', 'æ' => 'ae', 'ð' => 'd', 'þ' => 'th'];

        foreach ($groups as $base => $characters) {
            foreach (mb_str_split($characters, 1, 'UTF-8') as $character) {
                $map[$character] = $base;
            }
        }

        return self::$map = $map;
    }
}
