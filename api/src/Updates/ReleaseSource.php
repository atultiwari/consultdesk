<?php

declare(strict_types=1);

namespace ConsultDesk\Updates;

/**
 * Where new versions come from (GitHub releases; a fake in tests).
 */
interface ReleaseSource
{
    /**
     * The newest release, pre-releases included only when asked.
     *
     * @throws UpdateFailed when the source can't be reached
     */
    public function latest(bool $includePrereleases): ?Release;

    /**
     * Saves $url to $path, refusing anything larger than $maxBytes.
     *
     * @throws UpdateFailed
     */
    public function download(string $url, string $path, int $maxBytes): void;
}
