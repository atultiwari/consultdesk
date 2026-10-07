<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Coupon;

use DateTimeImmutable;
use PDO;

/**
 * Checks a code against a booking. Inside the booking transaction the coupon row is locked, so a
 * code with limited uses can't be used past its limit by two customers at once. A booking that is
 * rejected, cancelled or lapses gives its use back.
 */
final class Coupons
{
    /** Bookings in these states have used their coupon (a hold only while it lasts). */
    public const USING = ['held', 'awaiting_verification', 'confirmed', 'completed', 'no_show'];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @throws CouponRejected
     */
    public function apply(string $code, int $providerId, int $serviceId, int $priceMinor, ?string $email, DateTimeImmutable $now, bool $lock = false): AppliedCoupon
    {
        $coupon = $this->find(Coupon::normalise($code), $lock);
        if ($coupon === null || !$coupon->isLive($now) || !$coupon->appliesTo($providerId, $serviceId)) {
            throw CouponRejected::notValid();
        }
        if ($priceMinor <= 0) {
            throw CouponRejected::alreadyFree();
        }
        if ($coupon->maxUses !== null && $this->uses($coupon->id, $now) >= $coupon->maxUses) {
            throw CouponRejected::usedUp();
        }
        if ($coupon->oncePerEmail && $email !== null && $this->uses($coupon->id, $now, Coupon::emailKey($email)) > 0) {
            throw CouponRejected::alreadyUsed();
        }

        return new AppliedCoupon($coupon, $priceMinor, $coupon->discountFor($priceMinor));
    }

    private function find(string $code, bool $lock): ?Coupon
    {
        if ($code === '' || strlen($code) > 32) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT * FROM coupons WHERE code = :code' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute(['code' => $code]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? Coupon::fromRow($row) : null;
    }

    private function uses(int $couponId, DateTimeImmutable $now, ?string $emailKey = null): int
    {
        $placeholders = implode(', ', array_map(static fn(int $i): string => ':s' . $i, array_keys(self::USING)));
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM bookings WHERE coupon_id = :coupon AND status IN ({$placeholders})
               AND (status <> 'held' OR hold_expires_at > :now)" . ($emailKey === null ? '' : ' AND coupon_email = :email'),
        );
        $params = ['coupon' => $couponId, 'now' => $now->format('Y-m-d H:i:s')];
        foreach (self::USING as $i => $status) {
            $params['s' . $i] = $status;
        }
        if ($emailKey !== null) {
            $params['email'] = $emailKey;
        }
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }
}
