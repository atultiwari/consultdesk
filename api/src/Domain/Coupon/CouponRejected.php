<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Coupon;

use RuntimeException;

/**
 * A code that can't be used for this booking; the message is shown to the customer.
 */
final class CouponRejected extends RuntimeException
{
    public static function notValid(): self
    {
        return new self('That code isn’t valid for this session.');
    }

    public static function alreadyFree(): self
    {
        return new self('This session is already free.');
    }

    public static function usedUp(): self
    {
        return new self('This code has been used up.');
    }

    public static function alreadyUsed(): self
    {
        return new self('You’ve already used this code.');
    }
}
