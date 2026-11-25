<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Build;

use PDO;

/**
 * DDL of the database (schema 3) and of the working tables a build needs. Only what SQLite 3.34.1 offers: no
 * RETURNING, no STRICT, no `->>`, no math functions, and the text is normalised in PHP.
 */
final class Schema
{
    public const VERSION = 3;

    /** The application id of the database file (`MCGA`). */
    public const APPLICATION_ID = 1296254785;

    private function __construct()
    {
    }

    /**
     * Tables and virtual tables of a database, empty. Indexes that are better
     * built after the bulk load ({@see self::indexes()}) are not created here.
     */
    public static function create(PDO $db, bool $trigram): void
    {
        $db->exec('PRAGMA application_id = ' . self::APPLICATION_ID);
        $db->exec('PRAGMA user_version = ' . self::VERSION);
        $db->exec('CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL) WITHOUT ROWID');
        $db->exec(<<<'SQL'
            CREATE TABLE street (
              id            INTEGER PRIMARY KEY,
              name          TEXT NOT NULL,
              postcode      TEXT,
              city          TEXT NOT NULL,
              city_code     TEXT,
              district      TEXT,
              first_address INTEGER NOT NULL,
              address_count INTEGER NOT NULL,
              lat_e6        INTEGER NOT NULL,
              lon_e6        INTEGER NOT NULL,
              names         TEXT,
              part          INTEGER
            )
            SQL);
        $db->exec(<<<'SQL'
            CREATE TABLE address (
              id     INTEGER PRIMARY KEY,
              number TEXT,
              suffix TEXT,
              lat_e6 INTEGER NOT NULL,
              lon_e6 INTEGER NOT NULL,
              pc     TEXT
            )
            SQL);
        $db->exec('CREATE VIRTUAL TABLE street_rtree USING rtree_i32(id, min_lon, max_lon, min_lat, max_lat)');
        $db->exec("CREATE VIRTUAL TABLE street_fts USING fts5(doc, content='', columnsize=0, detail=none, tokenize='unicode61 remove_diacritics 2', prefix='1 2 3')");

        if ($trigram) {
            $db->exec("CREATE VIRTUAL TABLE street_tri USING fts5(doc, content='', columnsize=0, detail=full, tokenize='trigram')");
        }

        // Markers of the build, dropped before it is published (the heavy working tables live in the staging file).
        $db->exec('CREATE TABLE build_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL) WITHOUT ROWID');
        $db->exec('CREATE TABLE finalized (chunk INTEGER NOT NULL, bucket INTEGER NOT NULL, PRIMARY KEY (chunk, bucket)) WITHOUT ROWID');
    }

    public static function indexes(PDO $db): void
    {
        $db->exec('CREATE INDEX IF NOT EXISTS street_first ON street (first_address)');
    }

    /** Working tables of the staging database. */
    public static function createStaging(PDO $db): void
    {
        $db->exec('CREATE TABLE progress (chunk INTEGER PRIMARY KEY, byte_offset INTEGER NOT NULL, line INTEGER NOT NULL, rows INTEGER NOT NULL, rejected INTEGER NOT NULL, done INTEGER NOT NULL)');
    }

    public static function stagingTable(int $chunk, int $bucket): string
    {
        return sprintf('stg_%d_%d', $chunk, $bucket);
    }

    public static function createStagingTable(PDO $db, int $chunk, int $bucket): void
    {
        $db->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS stg.%s (line INTEGER PRIMARY KEY, skey TEXT NOT NULL, name TEXT NOT NULL, postcode TEXT, city TEXT NOT NULL, city_code TEXT NOT NULL, district TEXT, number TEXT, nsort INTEGER NOT NULL, suffix TEXT, lat_e6 INTEGER NOT NULL, lon_e6 INTEGER NOT NULL, extra TEXT, names TEXT, pc TEXT)',
            self::stagingTable($chunk, $bucket),
        ));
    }

    /** The streets of the chunks finalised so far, aggregated (staging file). */
    public static function createStreetTmp(PDO $db): void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS stg.street_tmp (skey TEXT PRIMARY KEY, src INTEGER NOT NULL, name TEXT NOT NULL, postcode TEXT, city TEXT NOT NULL, city_code TEXT NOT NULL, district TEXT, first_address INTEGER NOT NULL, n INTEGER NOT NULL, min_lat INTEGER NOT NULL, max_lat INTEGER NOT NULL, min_lon INTEGER NOT NULL, max_lon INTEGER NOT NULL, sum_lat INTEGER NOT NULL, sum_lon INTEGER NOT NULL, extra TEXT, names TEXT) WITHOUT ROWID');
    }
}
