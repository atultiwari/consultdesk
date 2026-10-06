<?php

declare(strict_types=1);

// Google Calendar per provider. (The admin panel offers the same in Phase 6.)
//   php bin/google.php connect <provider-slug> <google-email> [--replace]
//                                                     prints a consent link (valid 30 minutes, once) that only
//                                                     works for that Google account; --replace switches an
//                                                     existing connection to another account
//   php bin/google.php status <provider-slug>         connection, account and calendars in use
//   php bin/google.php calendars <provider-slug>      lists the account's calendars and their ids
//   php bin/google.php set-calendars <provider-slug> --busy=<id>[,<id>...] --target=<id>
//   php bin/google.php disconnect <provider-slug>     revokes access and forgets the tokens

use ConsultDesk\Bootstrap\AppServices;
use ConsultDesk\Infra\Config;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

$args = array_values(array_map('strval', is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : []));
$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

try {
    $services = new AppServices(Config::load(dirname(__DIR__) . '/config.php', getenv()));
    $calendar = $services->googleCalendar() ?? $fail('Google is not configured: set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET.');
    [$command, $slug] = [$args[1] ?? '', $args[2] ?? ''];

    $lookup = $services->db()->pdo()->prepare('SELECT id, name FROM providers WHERE slug = ?');
    $lookup->execute([$slug]);
    $provider = $lookup->fetch(PDO::FETCH_ASSOC);
    if (!is_array($provider)) {
        $fail('Usage: php bin/google.php connect <slug> <google-email> [--replace] | status|calendars|disconnect <slug> | set-calendars <slug> --busy=... --target=...');
    }
    $providerId = (int) $provider['id'];
    $connections = $services->googleConnections();

    switch ($command) {
        case 'connect':
            $oauth = $services->googleOAuth() ?? $fail('Google is not configured.');
            $email = $args[3] ?? '';
            if ($email === '' || str_starts_with($email, '--')) {
                $fail('Usage: connect <provider-slug> <google-email> [--replace]');
            }
            $url = $oauth->start($providerId, $email, in_array('--replace', $args, true));
            printf("Send this link to %s. It works once, for 30 minutes, and only for the Google account %s:\n%s\n", (string) $provider['name'], strtolower($email), $url);
            break;

        case 'status':
            $connection = $connections->find($providerId);
            if ($connection === null) {
                echo "Not connected.\n";
                break;
            }
            printf(
                "Account: %s\nStatus: %s\nBlocks availability: %s\nAdds events to: %s\n",
                $connection->accountEmail ?? '?',
                $connection->active ? 'connected' : 'disconnected (run connect again)',
                implode(', ', $connection->busyCalendarIds) ?: '(none)',
                $connection->targetCalendarId ?? 'primary'
            );
            break;

        case 'calendars':
            foreach ($calendar->calendars($providerId) as $entry) {
                printf("%s%s  %s%s\n", $entry->id, $entry->primary ? ' (primary)' : '', $entry->summary, $entry->writable ? '' : '  [read-only]');
            }
            break;

        case 'set-calendars':
            $options = getopt('', ['busy:', 'target:'], $rest) ?: [];
            $busy = array_values(array_filter(array_map('trim', explode(',', is_string($options['busy'] ?? null) ? $options['busy'] : ''))));
            $target = is_string($options['target'] ?? null) ? $options['target'] : '';
            if ($busy === [] || $target === '') {
                $fail('Usage: set-calendars <slug> --busy=<id>[,<id>...] --target=<id>');
            }
            $known = [];
            foreach ($calendar->calendars($providerId) as $entry) {
                $known[$entry->id] = $entry->writable;
            }
            foreach ([...$busy, $target] as $id) {
                if (!array_key_exists($id, $known)) {
                    $fail("Unknown calendar \"{$id}\"; run the calendars command to see the ids.");
                }
            }
            if ($known[$target] !== true) {
                $fail('Events can only be added to a calendar you can edit.');
            }
            $connections->setCalendars($providerId, $busy, $target);
            echo "Saved.\n";
            break;

        case 'disconnect':
            $calendar->disconnect($providerId);
            echo "Disconnected.\n";
            break;

        default:
            $fail('Usage: php bin/google.php connect|status|calendars|set-calendars|disconnect <provider-slug>');
    }
} catch (Throwable $e) {
    $fail('Google command failed: ' . $e->getMessage());
}
