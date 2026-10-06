<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Http;

use ConsultDesk\Bootstrap\AppServices;
use ConsultDesk\Http\AppFactory;
use ConsultDesk\Infra\Config;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Routes that never touch the database, so no connection is configured.
 */
final class HealthTest extends TestCase
{
    public function testHealthReturnsSuccessEnvelope(): void
    {
        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/health'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ['success' => true, 'data' => ['status' => 'ok'], 'error' => null, 'meta' => null],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function testUnknownRouteReturns404Envelope(): void
    {
        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/nope'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    /**
     * @return App<\Psr\Container\ContainerInterface|null>
     */
    private function app(): App
    {
        return AppFactory::create(new AppServices(Config::load('/nonexistent', [
            'APP_URL' => 'https://book.example.test',
            'APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'CRON_KEY' => str_repeat('c', 32),
            'DB_HOST' => 'unused',
            'DB_NAME' => 'unused',
            'DB_USER' => 'unused',
            'SMTP_HOST' => 'unused',
            'MAIL_FROM' => 'bookings@example.test',
        ])));
    }
}
