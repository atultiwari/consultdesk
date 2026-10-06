<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Calendar\GoogleConnectFailed;
use ConsultDesk\Calendar\GoogleOAuth;
use ConsultDesk\Http\ApiException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/google/callback: where Google sends the provider back. Answers with a small HTML page
 * because a person is looking at it.
 */
final class GoogleCallbackAction
{
    public function __construct(private readonly ?GoogleOAuth $oauth) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if ($this->oauth === null) {
            throw ApiException::notFound();
        }

        $query = $request->getQueryParams();
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        if ($code === '' || $state === '') {
            return self::page($response, 400, 'Google Calendar not connected', 'Access was not granted. You can close this page and start again from the connection link.');
        }

        try {
            $email = $this->oauth->complete($state, $code);
        } catch (GoogleConnectFailed $e) {
            return self::page($response, 400, 'Google Calendar not connected', $e->getMessage());
        }

        return self::page($response, 200, 'Google Calendar connected', "Bookings will now check {$email} for clashes and add confirmed sessions with a Google Meet link. You can close this page.");
    }

    private static function page(Response $response, int $status, string $title, string $message): Response
    {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $response->getBody()->write(sprintf(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>%1$s</title></head><body><h1>%1$s</h1><p>%2$s</p></body></html>',
            $e($title),
            $e($message),
        ));

        return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
