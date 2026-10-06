<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use ConsultDesk\Domain\Booking\BookingView;

/**
 * The Google Calendar event for a confirmed booking. The customer is an attendee and can see the
 * description, so it holds only what they already gave us — never the status-page token.
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
        foreach ($booking->answers as $question => $answer) {
            $lines[] = sprintf('%s: %s', ucfirst(str_replace('_', ' ', (string) $question)), is_scalar($answer) ? (is_bool($answer) ? ($answer ? 'Yes' : 'No') : (string) $answer) : '');
        }

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
