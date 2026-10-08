<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Updates;

use ConsultDesk\Updates\Release;
use ConsultDesk\Updates\ReleaseSource;
use ConsultDesk\Updates\UpdateFailed;

/**
 * Serves releases from local files instead of GitHub.
 */
final class FakeSource implements ReleaseSource
{
    public ?Release $latest = null;
    public bool $fail = false;

    public function __construct(private readonly string $dir) {}

    public function latest(bool $includePrereleases): ?Release
    {
        if ($this->fail) {
            throw new UpdateFailed('GitHub is down');
        }

        return $this->latest;
    }

    public function download(string $url, string $path, int $maxBytes): void
    {
        if (!str_starts_with($url, $this->dir) || !copy($url, $path)) {
            throw new UpdateFailed('download failed');
        }
    }
}
