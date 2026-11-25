<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/** Base of the tests: throw-away directories. */
abstract class TestCase extends BaseTestCase
{
    /** @var list<string> */
    private array $temporaryDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirs as $dir) {
            self::removeDir($dir);
        }

        $this->temporaryDirs = [];

        parent::tearDown();
    }

    /** A throwaway directory removed after the test. */
    protected function tempDir(string $prefix = 'mv'): string
    {
        $base = getenv('MAPCOVA_TEST_SCRATCH');
        $root = is_string($base) && $base !== '' ? $base : sys_get_temp_dir();
        $dir = $root . '/' . $prefix . '-' . bin2hex(random_bytes(6));

        mkdir($dir, 0o775, true);
        $this->temporaryDirs[] = $dir;

        return $dir;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($dir);
    }
}
