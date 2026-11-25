<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Build;

/**
 * Result of {@see SpaceEstimator::estimate()}.
 */
final class SpaceEstimate
{
    public function __construct(
        public readonly int $downloadBytes,
        public readonly int $finalBytes,
        public readonly int $peakBytes,
        public readonly int $existingBytes,
        public readonly ?float $freeBytes,
        public readonly ?int $quotaLeftBytes = null,
    ) {
    }

    /** Whether the disk and the quota hold the peak; an unknown free space is not a refusal. */
    public function fits(): bool
    {
        if ($this->freeBytes !== null && $this->freeBytes < $this->peakBytes) {
            return false;
        }

        // The old database is already inside the quota's "used" figure.
        return $this->quotaLeftBytes === null || $this->peakBytes - $this->existingBytes <= $this->quotaLeftBytes;
    }

    /**
     * The room the import can use: what is left on the disk, or under the data quota when that is less
     * (the old database is already counted in the quota, and is replaced). `PHP_INT_MAX` when neither is known.
     */
    public function roomBytes(): int
    {
        $rooms = array_filter([
            $this->freeBytes === null ? null : (int) $this->freeBytes,
            $this->quotaLeftBytes === null ? null : $this->quotaLeftBytes + $this->existingBytes,
        ], static fn (?int $room): bool => $room !== null);

        return $rooms === [] ? PHP_INT_MAX : min($rooms);
    }

    /**
     * The message key and parameters of a refusal for lack of room: `$key`
     * when only the disk is concerned, `<key>_quota` (one more parameter) when
     * a data quota is set. `$lead` are the parameters before the sizes, in MB
     * (`?` for an unknown free space).
     *
     * @param list<string|int> $lead
     *
     * @return array{0: string, 1: list<string|int>}
     */
    public function keyed(string $key, array $lead = []): array
    {
        $params = [
            ...$lead,
            (int) ceil($this->peakBytes / 1_000_000),
            $this->freeBytes === null ? '?' : (int) floor($this->freeBytes / 1_000_000),
        ];

        if ($this->quotaLeftBytes === null) {
            return [$key, $params];
        }

        return [$key . '_quota', [...$params, (int) floor($this->quotaLeftBytes / 1_000_000)]];
    }
}
