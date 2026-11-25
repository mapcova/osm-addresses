<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Tests\Support;

/** The string table of one block of {@see PbfWriter}: index 0 is the empty string, as the format wants. */
final class StringTable
{
    /** @var list<string> */
    private array $table = [''];

    /** @var array<string,int> */
    private array $index = ['' => 0];

    public function index(string $s): int
    {
        if (!isset($this->index[$s])) {
            $this->table[] = $s;
            $this->index[$s] = \count($this->table) - 1;
        }

        return $this->index[$s];
    }

    /**
     * @param array<string,string> $tags
     * @return array{list<int>,list<int>} key indexes, value indexes
     */
    public function tags(array $tags): array
    {
        $k = [];
        $v = [];
        foreach ($tags as $key => $val) {
            $k[] = $this->index((string) $key);
            $v[] = $this->index($val);
        }

        return [$k, $v];
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->table;
    }
}
