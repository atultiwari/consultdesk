<?php

declare(strict_types=1);

namespace ConsultDesk\Customer;

use ConsultDesk\Domain\Booking\BookingStatus;
use ConsultDesk\Domain\Booking\BookingView;
use ConsultDesk\Domain\Booking\BookingViewRepository;
use ConsultDesk\Http\BookingPresenter;
use ConsultDesk\Infra\Crypto;
use DateTimeImmutable;

/**
 * Everything booked with one email address, split into upcoming and past, each with the link to its
 * status page (the same one the booking emails carry).
 */
final class CustomerBookings
{
    private const LIMIT = 100;
    private const OPEN = [BookingStatus::Held, BookingStatus::AwaitingVerification, BookingStatus::Confirmed];

    public function __construct(
        private readonly BookingViewRepository $views,
        private readonly Crypto $crypto,
        private readonly string $appUrl,
    ) {}

    /**
     * @return array{upcoming: list<array<string, mixed>>, past: list<array<string, mixed>>}
     */
    public function for(string $email, DateTimeImmutable $now): array
    {
        $upcoming = [];
        $past = [];
        foreach ($this->views->findByEmail($email, self::LIMIT) as $view) {
            $open = in_array($view->status, self::OPEN, true) && !$view->holdLapsed($now) && $view->slot->end > $now;
            $item = [
                ...BookingPresenter::present($view, $now),
                // Only what's still ahead links to its status page (to pay or join); the rest is a record.
                'status_url' => $open ? $this->statusUrl($view) : null,
                'can_cancel' => self::canCancel($view, $now),
            ];
            if ($open) {
                $upcoming[] = $item;
            } else {
                $past[] = $item;
            }
        }

        return ['upcoming' => array_reverse($upcoming), 'past' => $past];
    }

    /**
     * This customer's booking with this ref, or null (also for someone else's).
     */
    public function find(string $email, string $ref): ?BookingView
    {
        $view = $this->views->findByRef($ref);

        return $view !== null && $view->customerEmail === $email ? $view : null;
    }

    /**
     * Customers cancel only what they haven't paid for; anything paid goes through the teacher,
     * who can refund.
     */
    public static function canCancel(BookingView $view, DateTimeImmutable $now): bool
    {
        return $view->status === BookingStatus::Held && !$view->holdLapsed($now) && $view->slot->start > $now;
    }

    private function statusUrl(BookingView $view): ?string
    {
        if ($view->publicTokenEnc === null) {
            return null;
        }

        return sprintf('%s/b/%s?t=%s', $this->appUrl, rawurlencode($view->ref), rawurlencode($this->crypto->decrypt($view->publicTokenEnc)));
    }
}
