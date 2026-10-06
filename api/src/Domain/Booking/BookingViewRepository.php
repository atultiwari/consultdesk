<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

interface BookingViewRepository
{
    public function findById(int $bookingId): ?BookingView;

    public function findByRef(string $ref): ?BookingView;

    /**
     * Who gets provider-side emails: the provider's notification email plus every owner, de-duplicated.
     *
     * @return list<string>
     */
    public function staffEmails(BookingView $booking): array;

    /**
     * @return list<string>
     */
    public function staffEmailsForProvider(int $providerId): array;
}
