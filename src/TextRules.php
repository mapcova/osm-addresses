<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses;

/**
 * Version of the text normalisation (`Text/TextNormalizer.php`) the databases are built with. It is written
 * in `meta.text_rules` of the database: a reader whose own rules have another version should not trust the
 * indexed text. Bump it with every change of the normaliser.
 */
final class TextRules
{
    public const VERSION = 1;

    private function __construct()
    {
    }
}
