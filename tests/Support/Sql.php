<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Support;

use PDO;

/**
 * One-line reads of a SQLite file, for the tests.
 */
final class Sql
{
    private function __construct()
    {
    }

    public static function open(string $path): PDO
    {
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $db;
    }

    public static function value(PDO $db, string $sql): string
    {
        $statement = $db->query($sql);

        return $statement === false ? '' : (string) $statement->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(PDO $db, string $sql): array
    {
        $statement = $db->query($sql);

        return $statement === false ? [] : array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return list<mixed>
     */
    public static function column(PDO $db, string $sql): array
    {
        $statement = $db->query($sql);

        return $statement === false ? [] : array_values($statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
