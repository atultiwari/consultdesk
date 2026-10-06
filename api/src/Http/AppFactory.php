<?php

declare(strict_types=1);

namespace ConsultDesk\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

final class AppFactory
{
    /**
     * @return App<ContainerInterface|null>
     */
    public static function create(bool $displayErrorDetails = false, bool $logErrors = true): App
    {
        $app = SlimAppFactory::create();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware($displayErrorDetails, $logErrors, $logErrors);

        $app->get('/api/health', static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            return JsonResponse::success($response, ['status' => 'ok']);
        });

        return $app;
    }
}
