<?php

declare(strict_types=1);

namespace ConsultDesk\Http;

use ConsultDesk\Admin\AdminBookings;
use ConsultDesk\Admin\BlockedTimes;
use ConsultDesk\Admin\ProviderSettings;
use ConsultDesk\Admin\ServiceSettings;
use ConsultDesk\Admin\WeeklyHours;
use ConsultDesk\Bootstrap\AppServices;
use ConsultDesk\Http\Action\AdminBookingActions;
use ConsultDesk\Http\Action\AdminProviderActions;
use ConsultDesk\Http\Action\AdminScheduleActions;
use ConsultDesk\Http\Action\AdminServiceActions;
use Slim\Routing\RouteCollectorProxy;

/**
 * Signed-in admin endpoints (below /api/admin, behind AdminAuth). Each action checks the
 * signed-in person's scope; writes are audited.
 */
final class AdminRoutes
{
    private const ID = '{id:[1-9][0-9]{0,18}}';

    /**
     * @param RouteCollectorProxy<\Psr\Container\ContainerInterface|null> $admin
     */
    public static function register(RouteCollectorProxy $admin, AppServices $services): void
    {
        $pdo = static fn(): \PDO => $services->db()->pdo();
        $bookings = static fn(): AdminBookingActions => new AdminBookingActions(new AdminBookings($pdo()), $services->bookingService(), $services->clock());
        $providers = static fn(): AdminProviderActions => new AdminProviderActions(new ProviderSettings($pdo()), $services->auditLog());
        $catalog = static fn(): AdminServiceActions => new AdminServiceActions(new ProviderSettings($pdo()), new ServiceSettings($pdo()), $services->auditLog());
        $schedule = static fn(): AdminScheduleActions => new AdminScheduleActions(
            new ProviderSettings($pdo()),
            new ServiceSettings($pdo()),
            new WeeklyHours($pdo(), $services->db()),
            new BlockedTimes($pdo()),
            $services->auditLog(),
            $services->clock(),
        );
        $id = self::ID;

        $admin->get('/dashboard', static fn($rq, $rs) => $bookings()->dashboard($rq, $rs));
        $admin->get('/bookings', static fn($rq, $rs) => $bookings()->list($rq, $rs));
        $admin->get("/bookings/{$id}", static fn($rq, $rs, array $a) => $bookings()->show($rq, $rs, $a));
        $admin->post("/bookings/{$id}/{action:confirm|reject|cancel|complete|no-show}", static fn($rq, $rs, array $a) => $bookings()->act($rq, $rs, $a));

        $admin->get('/providers', static fn($rq, $rs) => $providers()->list($rq, $rs));
        $admin->post('/providers', static fn($rq, $rs) => $providers()->create($rq, $rs));
        $admin->patch("/providers/{$id}", static fn($rq, $rs, array $a) => $providers()->update($rq, $rs, $a));

        $admin->get("/providers/{$id}/services", static fn($rq, $rs, array $a) => $catalog()->list($rq, $rs, $a));
        $admin->post("/providers/{$id}/services", static fn($rq, $rs, array $a) => $catalog()->create($rq, $rs, $a));
        $admin->patch("/services/{$id}", static fn($rq, $rs, array $a) => $catalog()->update($rq, $rs, $a));

        $admin->get("/providers/{$id}/availability", static fn($rq, $rs, array $a) => $schedule()->availability($rq, $rs, $a));
        $admin->put("/providers/{$id}/availability", static fn($rq, $rs, array $a) => $schedule()->replaceAvailability($rq, $rs, $a));
        $admin->get('/blocked', static fn($rq, $rs) => $schedule()->listBlocked($rq, $rs));
        $admin->post('/blocked', static fn($rq, $rs) => $schedule()->createBlocked($rq, $rs));
        $admin->delete("/blocked/{$id}", static fn($rq, $rs, array $a) => $schedule()->deleteBlocked($rq, $rs, $a));
    }
}
