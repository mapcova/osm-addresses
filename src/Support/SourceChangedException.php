<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Support;

use RuntimeException;

/**
 * The pinned build moved under us: a ranged request answered `200` instead of
 * `206`, or `If-Range`/`If-Match` produced a `412`. The build fails with an
 * explicit "source build changed or disappeared" message rather than writing a
 * corrupt file.
 */
final class SourceChangedException extends RuntimeException implements PermanentFailure
{
    private int $status = 0;

    public static function forStatus(int $status, string $url): self
    {
        $exception = new self(sprintf(
            'The source build changed or disappeared (HTTP %d for %s); start the build again.',
            $status,
            $url,
        ));
        $exception->status = $status;

        return $exception;
    }

    /**
     * The status that produced the verdict, `0` when it came from elsewhere.
     * A caller with nothing pinned yet reads it to report what actually
     * happened rather than a build change it cannot have observed.
     */
    public function status(): int
    {
        return $this->status;
    }
}
