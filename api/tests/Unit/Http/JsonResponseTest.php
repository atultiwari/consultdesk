<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Http;

use ConsultDesk\Http\JsonResponse;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

final class JsonResponseTest extends TestCase
{
    public function testErrorWritesFailureEnvelopeWithStatus(): void
    {
        $response = JsonResponse::error(new Response(), 'slot_taken', 'That slot was just booked.', 409);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            ['success' => false, 'data' => null, 'error' => ['code' => 'slot_taken', 'message' => 'That slot was just booked.'], 'meta' => null],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function testSuccessIncludesMetaWhenGiven(): void
    {
        $response = JsonResponse::success(new Response(), [1, 2], ['total' => 2], 201);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['total' => 2], json_decode((string) $response->getBody(), true)['meta']);
    }
}
