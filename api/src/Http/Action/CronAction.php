<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Cron\CronRunner;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Web-triggered cron for hosts whose scheduler can only call a URL. The CLI (bin/cron.php) is preferred.
 */
final class CronAction
{
    public function __construct(
        private readonly CronRunner $runner,
        #[\SensitiveParameter]
        private readonly string $cronKey,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        // Prefer the header: query strings end up in access logs.
        $key = $request->getHeaderLine('X-Cron-Key') !== '' ? $request->getHeaderLine('X-Cron-Key') : ($request->getQueryParams()['key'] ?? '');
        if (!is_string($key) || !hash_equals($this->cronKey, $key)) {
            throw ApiException::forbidden();
        }

        return JsonResponse::success($response, $this->runner->run()->toArray());
    }
}
