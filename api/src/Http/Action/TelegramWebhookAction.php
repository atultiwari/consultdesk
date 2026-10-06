<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\JsonInput;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Telegram\TelegramBot;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/webhooks/telegram. Telegram sends the secret registered with setWebhook in a header;
 * anything without it is refused before the body is read.
 */
final class TelegramWebhookAction
{
    private const SECRET_HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    public function __construct(
        private readonly ?TelegramBot $bot,
        #[\SensitiveParameter]
        private readonly ?string $secret,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if ($this->bot === null || $this->secret === null) {
            throw ApiException::notFound();
        }
        if (!hash_equals($this->secret, $request->getHeaderLine(self::SECRET_HEADER))) {
            throw ApiException::forbidden();
        }

        $this->bot->handle(JsonInput::decode($request));

        return JsonResponse::success($response, ['ok' => true]);
    }
}
