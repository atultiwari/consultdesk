<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Http;

use ConsultDesk\Http\AppFactory;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class HealthTest extends TestCase
{
    public function testHealthReturnsSuccessEnvelope(): void
    {
        $app = AppFactory::create();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/health');

        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ['success' => true, 'data' => ['status' => 'ok'], 'error' => null, 'meta' => null],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function testUnknownRouteReturns404(): void
    {
        $app = AppFactory::create();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/nope');

        self::assertSame(404, $app->handle($request)->getStatusCode());
    }
}
