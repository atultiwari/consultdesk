<?php

declare(strict_types=1);

namespace ConsultDesk\Payments;

use ConsultDesk\Infra\Settings;

/**
 * Whether Razorpay payments use the live (real money) keys or the test keys (settings key
 * "payments_mode"). The owner switches it on the Payments page; until they do, PAYMENTS_LIVE=1 in
 * config.php is the starting value, so older installs that went live there stay live.
 */
final class PaymentMode
{
    public const KEY = 'payments_mode';
    public const TEST = 'test';
    public const LIVE = 'live';

    public function __construct(
        private readonly Settings $settings,
        private readonly bool $liveByDefault = false,
    ) {}

    public function isLive(): bool
    {
        $stored = $this->settings->get(self::KEY);

        return array_key_exists('live', $stored) ? $stored['live'] === true : $this->liveByDefault;
    }

    /** "test" or "live". */
    public function current(): string
    {
        return $this->isLive() ? self::LIVE : self::TEST;
    }

    public function set(bool $live): void
    {
        $this->settings->put(self::KEY, ['live' => $live]);
    }
}
