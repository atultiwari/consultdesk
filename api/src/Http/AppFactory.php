<?php

declare(strict_types=1);

namespace ConsultDesk\Http;

use ConsultDesk\Bootstrap\AppServices;
use ConsultDesk\Http\Action\AdminAuthActions;
use ConsultDesk\Http\Action\BookingActions;
use ConsultDesk\Http\Action\CronAction;
use ConsultDesk\Http\Action\GoogleCallbackAction;
use ConsultDesk\Http\Action\MediaAction;
use ConsultDesk\Http\Action\ProviderActions;
use ConsultDesk\Http\Action\TelegramWebhookAction;
use ConsultDesk\Http\Middleware\AdminAuth;
use ConsultDesk\Http\Middleware\ErrorHandling;
use ConsultDesk\Http\Middleware\RateLimit;
use ConsultDesk\Http\Middleware\SecurityHeaders;
use ConsultDesk\Infra\ImageStore;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Routing\RouteCollectorProxy;

final class AppFactory
{
    private const MINUTE = 60;
    private const HOUR = 3600;

    /**
     * @return App<ContainerInterface|null>
     */
    public static function create(AppServices $services): App
    {
        $app = SlimAppFactory::create();
        $app->addRoutingMiddleware();
        $app->add(new ErrorHandling($app->getResponseFactory(), $services->config->debug));
        $app->add(new SecurityHeaders());

        $app->group('/api', static function (RouteCollectorProxy $api) use ($services): void {
            $api->get('/health', static fn(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface => JsonResponse::success($response, ['status' => 'ok']));

            $limit = static fn(string $bucket, int $max, int $window): RateLimit => new RateLimit(static fn() => $services->rateLimiter(), $bucket, $max, $window, $services->clientIp());
            $providers = static fn(): ProviderActions => new ProviderActions($services->catalog(), $services->slotFinder(), $services->clock(), $services->config->appUrl);
            $bookings = static fn(): BookingActions => new BookingActions(
                $services->catalog(),
                $services->bookingService(),
                $services->bookingViews(),
                $services->clock(),
                $services->rateLimiter(),
                $services->config->appUrl,
            );

            $api->get('/site', static fn($rq, $rs) => $providers()->site($rq, $rs))->add($limit('read', 120, self::MINUTE));
            $api->get('/providers', static fn($rq, $rs) => $providers()->list($rq, $rs))->add($limit('read', 120, self::MINUTE));
            $api->get('/providers/{provider}', static fn($rq, $rs, array $a) => $providers()->show($rq, $rs, $a))->add($limit('read', 120, self::MINUTE));
            $api->get('/providers/{provider}/services/{service}/slots', static fn($rq, $rs, array $a) => $providers()->slots($rq, $rs, $a))->add($limit('read', 120, self::MINUTE));

            $api->post('/bookings', static fn($rq, $rs) => $bookings()->create($rq, $rs))->add($limit('book', 10, self::HOUR));
            $api->get('/bookings/{ref}', static fn($rq, $rs, array $a) => $bookings()->show($rq, $rs, $a))->add($limit('status', 60, self::MINUTE));
            $api->post('/bookings/{ref}/utr', static fn($rq, $rs, array $a) => $bookings()->submitUtr($rq, $rs, $a))->add($limit('utr', 10, self::HOUR));

            $api->post('/webhooks/telegram', static fn($rq, $rs) => (new TelegramWebhookAction($services->telegramBot(), $services->config->telegram?->webhookSecret))($rq, $rs))->add($limit('telegram', 600, self::MINUTE));

            $api->get('/google/callback', static fn($rq, $rs) => (new GoogleCallbackAction($services->googleOAuth()))($rq, $rs))->add($limit('google', 30, self::MINUTE));

            $auth = static fn(): AdminAuthActions => new AdminAuthActions(
                $services->config->adminPath,
                $services->authService(),
                $services->passwordResets(),
                $services->adminCookie(),
                $services->clientIp(),
            );
            $api->get('/admin/entry/{path}', static fn($rq, $rs, array $a) => $auth()->entry($rq, $rs, $a))->add($limit('admin-entry', 30, self::MINUTE));
            $api->post('/admin/login', static fn($rq, $rs) => $auth()->login($rq, $rs))->add($limit('admin-login', 30, self::MINUTE));
            $api->post('/admin/password/forgot', static fn($rq, $rs) => $auth()->forgot($rq, $rs))->add($limit('admin-forgot', 5, self::HOUR));
            $api->post('/admin/password/reset', static fn($rq, $rs) => $auth()->reset($rq, $rs))->add($limit('admin-reset', 10, self::HOUR));

            $api->group('/admin', static function (RouteCollectorProxy $admin) use ($services, $auth): void {
                $admin->get('/me', static fn($rq, $rs) => $auth()->me($rq, $rs));
                $admin->post('/logout', static fn($rq, $rs) => $auth()->logout($rq, $rs));
                $admin->post('/logout-all', static fn($rq, $rs) => $auth()->logoutAll($rq, $rs));
                AdminRoutes::register($admin, $services);
            })->add(new AdminAuth(static fn() => $services->sessions(), $services->adminCookie()))->add($limit('admin', 600, self::MINUTE));

            $api->get('/media/{name:' . ImageStore::NAME . '}', static fn($rq, $rs, array $a) => (new MediaAction($services->imageStore()))($rq, $rs, $a))->add($limit('media', 600, self::MINUTE));

            $api->get('/cron', static fn($rq, $rs) => (new CronAction($services->cronRunner(), $services->config->cronKey))($rq, $rs))->add($limit('cron', 30, self::MINUTE));
        });

        return $app;
    }
}
