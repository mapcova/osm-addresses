<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Build;

/**
 * The resume point of a build, small enough to be saved between two slices. The fine
 * progress (rows ingested, streets indexed…) lives in the working databases,
 * in the same transaction as the work it describes: this state only says
 * which phase and which chunk to look at, so a stale state is harmless.
 */
final class BuildState
{
    public const PLAN = 'plan';
    public const DOWNLOAD = 'download';
    public const EXTRACT = 'extract';
    public const INGEST = 'ingest';
    public const FINALIZE = 'finalize';
    public const STREETS = 'streets';
    public const FTS = 'fts';
    public const VERIFY = 'verify';
    public const PUBLISH = 'publish';
    public const DONE = 'done';

    /**
     * @param array<string, array{etag: ?string, last_modified: ?string}> $versions per chunk key
     * @param array{etag: ?string, last_modified: ?string}                $version  of the source's version URL
     */
    public function __construct(
        public string $buildId,
        public string $country,
        public string $phase = self::PLAN,
        public int $chunk = 0,
        public int $bucket = 0,
        public int $downloaded = 0,
        public ?int $size = null,
        public ?string $etag = null,
        public ?string $lastModified = null,
        public ?string $sha256 = null,
        public ?string $url = null,
        public int $resets = 0,
        public int $totalBytes = 0,
        public int $doneBytes = 0,
        public int $streetsEstimate = 0,
        public array $versions = [],
        public array $version = ['etag' => null, 'last_modified' => null],
        public int $extracted = 0,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $int = static fn (string $key): int => is_int($data[$key] ?? null) ? $data[$key] : 0;
        $string = static fn (string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;
        $phase = $string('phase') ?? self::PLAN;
        $buildId = $string('buildId') ?? '';
        $buildId = self::isValidId($buildId) ? $buildId : '';

        $versions = [];

        foreach (is_array($data['versions'] ?? null) ? $data['versions'] : [] as $key => $version) {
            if (is_array($version)) {
                $versions[(string) $key] = [
                    'etag' => is_string($version['etag'] ?? null) ? $version['etag'] : null,
                    'last_modified' => is_string($version['last_modified'] ?? null) ? $version['last_modified'] : null,
                ];
            }
        }

        $version = is_array($data['version'] ?? null) ? $data['version'] : [];

        return new self(
            $buildId,
            $string('country') ?? '',
            $phase,
            $int('chunk'),
            $int('bucket'),
            $int('downloaded'),
            is_int($data['size'] ?? null) ? $data['size'] : null,
            $string('etag'),
            $string('lastModified'),
            $string('sha256'),
            $string('url'),
            $int('resets'),
            $int('totalBytes'),
            $int('doneBytes'),
            $int('streetsEstimate'),
            $versions,
            [
                'etag' => is_string($version['etag'] ?? null) ? $version['etag'] : null,
                'last_modified' => is_string($version['last_modified'] ?? null) ? $version['last_modified'] : null,
            ],
            $int('extracted'),
        );
    }

    /** The shape of the ids this version makes (they name files): `20261118-1f3a9c`. */
    public static function isValidId(string $id): bool
    {
        return preg_match('/^\d{8}-[0-9a-f]{6}$/', $id) === 1;
    }

    public function isDone(): bool
    {
        return $this->phase === self::DONE;
    }
}
