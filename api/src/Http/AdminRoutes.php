<?php

declare(strict_types=1);

namespace ConsultDesk\Http;

use ConsultDesk\Bootstrap\AppServices;
use Slim\Routing\RouteCollectorProxy;

/**
 * Signed-in admin endpoints (below /api/admin, behind AdminAuth).
 */
final class AdminRoutes
{
    /**
     * @param RouteCollectorProxy<\Psr\Container\ContainerInterface|null> $admin
     */
    public static function register(RouteCollectorProxy $admin, AppServices $services): void {}
}
