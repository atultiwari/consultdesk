<?php

declare(strict_types=1);

namespace ConsultDesk\Updates;

use ConsultDesk\Infra\Clock;
use ConsultDesk\Infra\Settings;
use ConsultDesk\Version;

/**
 * Whether a newer version is out. Asks GitHub at most every 12 hours (cron calls checkIfDue daily);
 * the answer is kept in settings, so pages never wait for GitHub.
 */
final class UpdateChecker
{
    public const KEY = 'updates';
    private const EVERY_SECONDS = 12 * 3600;

    public function __construct(
        private readonly ReleaseSource $source,
        private readonly Settings $settings,
        private readonly Clock $clock,
        private readonly bool $includePrereleases = false,
        private readonly string $current = Version::CURRENT,
    ) {}

    /**
     * @return array{current: string, latest: ?array<string, mixed>, available: bool, checked_at: ?string, error: ?string, last_update: mixed}
     */
    public function status(): array
    {
        $stored = $this->settings->get(self::KEY);
        $latest = is_array($stored['latest'] ?? null) ? Release::fromArray($stored['latest']) : null;

        return [
            'current' => $this->current,
            'latest' => $latest?->toArray(),
            'available' => $latest !== null && self::newer($latest->version, $this->current),
            'checked_at' => is_string($stored['checked_at'] ?? null) ? $stored['checked_at'] : null,
            'error' => is_string($stored['error'] ?? null) ? $stored['error'] : null,
            'last_update' => $stored['last_update'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed> the status after asking GitHub
     */
    public function check(): array
    {
        $now = $this->clock->now()->format(DATE_ATOM);
        try {
            $latest = $this->source->latest($this->includePrereleases);
            $this->settings->put(self::KEY, [...$this->settings->get(self::KEY), 'latest' => $latest?->toArray(), 'checked_at' => $now, 'error' => null]);
        } catch (UpdateFailed $e) {
            $this->settings->put(self::KEY, [...$this->settings->get(self::KEY), 'checked_at' => $now, 'error' => $e->getMessage()]);
        }

        return $this->status();
    }

    /**
     * For cron: asks again only when the last answer is older than 12 hours.
     *
     * @return int 1 when it asked
     */
    public function checkIfDue(): int
    {
        $checked = $this->settings->get(self::KEY)['checked_at'] ?? null;
        $due = !is_string($checked) || $this->clock->now()->getTimestamp() - (int) strtotime($checked) >= self::EVERY_SECONDS;
        if (!$due) {
            return 0;
        }
        $this->check();

        return 1;
    }

    public function release(): ?Release
    {
        $stored = $this->settings->get(self::KEY)['latest'] ?? null;

        return is_array($stored) ? Release::fromArray($stored) : null;
    }

    public static function newer(string $candidate, string $current): bool
    {
        return version_compare(ltrim($candidate, 'v'), ltrim($current, 'v'), '>');
    }
}
