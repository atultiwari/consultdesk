<?php

declare(strict_types=1);

namespace ConsultDesk\Payments\Razorpay;

use InvalidArgumentException;

/**
 * The organisation's default Razorpay keys from .env (RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET and
 * optionally RAZORPAY_WEBHOOK_SECRET). Keys saved on the Payments page take precedence; removing
 * those falls back to these, so resetting the database keeps payments working. Live keys
 * (rzp_live_…) are used only once the owner switches the Payments page to Live.
 */
final class RazorpayEnvKeys
{
    private const KEY_ID = '/^rzp_(test|live)_[A-Za-z0-9]{14,}$/';
    private const MIN_SECRET = 16;

    /**
     * @param array<string, string> $values
     */
    public static function fromValues(array $values): ?RazorpayCredentials
    {
        $keyId = trim($values['RAZORPAY_KEY_ID'] ?? '');
        $secret = $values['RAZORPAY_KEY_SECRET'] ?? '';
        $webhook = $values['RAZORPAY_WEBHOOK_SECRET'] ?? '';
        if ($keyId === '' && $secret === '' && $webhook === '') {
            return null;
        }
        if (preg_match(self::KEY_ID, $keyId) !== 1) {
            throw new InvalidArgumentException('RAZORPAY_KEY_ID must be a Key ID from Razorpay (rzp_test_… or rzp_live_…).');
        }
        if (!self::looksLikeSecret($secret)) {
            throw new InvalidArgumentException('RAZORPAY_KEY_SECRET must be the Key Secret Razorpay showed with RAZORPAY_KEY_ID.');
        }
        if ($webhook !== '' && !self::looksLikeSecret($webhook)) {
            throw new InvalidArgumentException(sprintf('RAZORPAY_WEBHOOK_SECRET must be at least %d characters without spaces.', self::MIN_SECRET));
        }

        return new RazorpayCredentials($keyId, $secret, $webhook === '' ? null : $webhook);
    }

    private static function looksLikeSecret(#[\SensitiveParameter] string $value): bool
    {
        return strlen($value) >= self::MIN_SECRET && preg_match('/\s/', $value) !== 1;
    }
}
