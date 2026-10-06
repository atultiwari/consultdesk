<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use ConsultDesk\Domain\Booking\BookingView;

/**
 * The Google Calendar event for a confirmed booking. The customer is an attendee and can see the
 * description, so it holds no status-page token and no free-text intake answers (Google would send
 * that text from the provider's account to whatever address was entered). Staff see answers in email.
 */
final class EventBody
{
    private const RFC3339_UTC = 'Y-m-d\TH:i:s\Z';

    /**
     * @return array<string, mixed>
     */
    public static function for(BookingView $booking, string $eventId): array
    {
        $lines = [
            sprintf('%s with %s', $booking->serviceTitle, $booking->providerName),
            sprintf('Booking %s', $booking->ref),
            sprintf('Customer: %s <%s>%s', $booking->customerName, $booking->customerEmail, $booking->customerPhone === null ? '' : ', ' . $booking->customerPhone),
        ];

        return [
            'id' => $eventId,
            'summary' => sprintf('%s — %s', $booking->serviceTitle, $booking->customerName),
            'description' => implode("\n", $lines),
            'start' => ['dateTime' => $booking->slot->start->format(self::RFC3339_UTC), 'timeZone' => $booking->providerTimezone],
            'end' => ['dateTime' => $booking->slot->end->format(self::RFC3339_UTC), 'timeZone' => $booking->providerTimezone],
            'attendees' => [['email' => $booking->customerEmail, 'displayName' => $booking->customerName]],
            'conferenceData' => ['createRequest' => [
                'requestId' => $eventId,
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ]],
            'reminders' => ['useDefault' => true],
            'extendedProperties' => ['private' => ['consultdesk_ref' => $booking->ref]],
        ];
    }
}
