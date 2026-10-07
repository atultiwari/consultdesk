<?php

declare(strict_types=1);

namespace ConsultDesk\Http;

use ConsultDesk\Admin\AdminBookings;
use ConsultDesk\Admin\BlockedTimes;
use ConsultDesk\Admin\Passwords;
use ConsultDesk\Admin\ProviderSettings;
use ConsultDesk\Admin\ServiceSettings;
use ConsultDesk\Admin\WeeklyHours;
use ConsultDesk\Bootstrap\AppServices;
use ConsultDesk\Http\Action\AdminBookingActions;
use ConsultDesk\Http\Action\AdminImageActions;
use ConsultDesk\Http\Action\AdminIntegrationActions;
use ConsultDesk\Http\Action\AdminMeActions;
use ConsultDesk\Http\Action\AdminPaymentActions;
use ConsultDesk\Http\Action\AdminProviderActions;
use ConsultDesk\Http\Action\AdminScheduleActions;
use ConsultDesk\Http\Action\AdminServiceActions;
use ConsultDesk\Http\Action\AdminSetupActions;
use ConsultDesk\Http\Action\AdminSystemActions;
use ConsultDesk\Http\Action\AdminUserActions;
use ConsultDesk\Http\Middleware\RateLimit;
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
        $providers = static fn(): AdminProviderActions => new AdminProviderActions(new ProviderSettings($pdo()), $services->auditLog(), $services->siteSetup());
        $setup = static fn(): AdminSetupActions => new AdminSetupActions($services->siteSetup(), $services->auditLog());
        $catalog = static fn(): AdminServiceActions => new AdminServiceActions(new ProviderSettings($pdo()), new ServiceSettings($pdo()), $services->auditLog());
        $schedule = static fn(): AdminScheduleActions => new AdminScheduleActions(
            new ProviderSettings($pdo()),
            new ServiceSettings($pdo()),
            new WeeklyHours($pdo(), $services->db()),
            new BlockedTimes($pdo()),
            $services->auditLog(),
            $services->clock(),
        );
        $users = static fn(): AdminUserActions => new AdminUserActions(
            $services->userDirectory(),
            $services->adminUsers(),
            new ProviderSettings($pdo()),
            $services->passwordResets(),
            $services->sessions(),
            $services->telegramLinks(),
            $services->db(),
            $services->auditLog(),
        );
        $me = static fn(): AdminMeActions => new AdminMeActions($services->adminUsers(), new Passwords(), $services->sessions(), $services->auditLog());
        $images = static fn(): AdminImageActions => new AdminImageActions($services->settings(), $services->imageStore(), new ProviderSettings($pdo()), $services->auditLog());
        $integrations = static fn(): AdminIntegrationActions => new AdminIntegrationActions(
            new ProviderSettings($pdo()),
            $services->telegramLinks(),
            $services->googleConnections(),
            $services->googleOAuth(),
            $services->googleCalendar(),
            $services->auditLog(),
        );
        $payments = static fn(): AdminPaymentActions => new AdminPaymentActions(
            $services->settings(),
            $services->gatewayKeys(),
            $services->razorpayApi(),
            new ProviderSettings($pdo()),
            $services->auditLog(),
            $pdo(),
            $services->config->appUrl,
        );
        $system = static fn(): AdminSystemActions => new AdminSystemActions($services->systemStatus(), $services->auditLog(), $services->backup(), $services->adminUsers(), new Passwords(), $services->rateLimiter());
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

        $admin->patch('/me', static fn($rq, $rs) => $me()->rename($rq, $rs));
        // The current-password check must not become a way to guess it from a stolen session.
        $admin->post('/me/password', static fn($rq, $rs) => $me()->changePassword($rq, $rs))
            ->add(new RateLimit(static fn() => $services->rateLimiter(), 'admin-password-change', 10, 3600, $services->clientIp()));
        $admin->get('/me/telegram', static fn($rq, $rs) => $integrations()->myTelegram($rq, $rs));
        $admin->post('/me/telegram/link', static fn($rq, $rs) => $integrations()->myTelegramLink($rq, $rs));
        $admin->delete('/me/telegram', static fn($rq, $rs) => $integrations()->myTelegramUnlink($rq, $rs));

        $admin->get('/users', static fn($rq, $rs) => $users()->list($rq, $rs));
        $admin->post('/users', static fn($rq, $rs) => $users()->create($rq, $rs));
        $admin->patch("/users/{$id}", static fn($rq, $rs, array $a) => $users()->update($rq, $rs, $a));
        $admin->post("/users/{$id}/invite", static fn($rq, $rs, array $a) => $users()->invite($rq, $rs, $a));

        $admin->get('/branding', static fn($rq, $rs) => $images()->branding($rq, $rs));
        $admin->put('/branding', static fn($rq, $rs) => $images()->saveBranding($rq, $rs));
        $admin->post('/branding/logo', static fn($rq, $rs) => $images()->uploadLogo($rq, $rs));
        $admin->delete('/branding/logo', static fn($rq, $rs) => $images()->removeLogo($rq, $rs));
        $admin->post("/providers/{$id}/photo", static fn($rq, $rs, array $a) => $images()->uploadPhoto($rq, $rs, $a));
        $admin->delete("/providers/{$id}/photo", static fn($rq, $rs, array $a) => $images()->removePhoto($rq, $rs, $a));

        $admin->get("/providers/{$id}/integrations", static fn($rq, $rs, array $a) => $integrations()->status($rq, $rs, $a));
        $admin->post("/providers/{$id}/telegram/link", static fn($rq, $rs, array $a) => $integrations()->telegramLink($rq, $rs, $a));
        $admin->delete("/providers/{$id}/telegram", static fn($rq, $rs, array $a) => $integrations()->telegramUnlink($rq, $rs, $a));
        $admin->post("/providers/{$id}/google/connect", static fn($rq, $rs, array $a) => $integrations()->googleConnect($rq, $rs, $a));
        $admin->get("/providers/{$id}/google/calendars", static fn($rq, $rs, array $a) => $integrations()->googleCalendars($rq, $rs, $a));
        $admin->put("/providers/{$id}/google/calendars", static fn($rq, $rs, array $a) => $integrations()->googleSetCalendars($rq, $rs, $a));
        $admin->delete("/providers/{$id}/google", static fn($rq, $rs, array $a) => $integrations()->googleDisconnect($rq, $rs, $a));

        $admin->get('/payments', static fn($rq, $rs) => $payments()->show($rq, $rs));
        $admin->put('/payments/methods', static fn($rq, $rs) => $payments()->saveMethods($rq, $rs));
        $admin->put('/payments/razorpay', static fn($rq, $rs) => $payments()->saveOrgKeys($rq, $rs));
        $admin->delete('/payments/razorpay', static fn($rq, $rs) => $payments()->removeOrgKeys($rq, $rs));
        $admin->post('/payments/razorpay/check', static fn($rq, $rs) => $payments()->check($rq, $rs));
        $admin->post('/payments/razorpay/offer-everywhere', static fn($rq, $rs) => $payments()->offerEverywhere($rq, $rs));
        $admin->post('/payments/razorpay/webhook-secret', static fn($rq, $rs) => $payments()->newWebhookSecret($rq, $rs));
        $admin->put("/providers/{$id}/razorpay", static fn($rq, $rs, array $a) => $payments()->saveProviderKeys($rq, $rs, $a));
        $admin->post("/providers/{$id}/razorpay/check", static fn($rq, $rs, array $a) => $payments()->checkProviderKeys($rq, $rs, $a));
        $admin->delete("/providers/{$id}/razorpay", static fn($rq, $rs, array $a) => $payments()->removeProviderKeys($rq, $rs, $a));

        $admin->get('/setup', static fn($rq, $rs) => $setup()->show($rq, $rs));
        $admin->put('/setup/mode', static fn($rq, $rs) => $setup()->setMode($rq, $rs));
        $admin->post('/setup/teacher', static fn($rq, $rs) => $setup()->saveTeacher($rq, $rs));
        $admin->post('/setup/sessions', static fn($rq, $rs) => $setup()->addSessions($rq, $rs));
        $admin->post('/setup/complete', static fn($rq, $rs) => $setup()->complete($rq, $rs));

        $admin->get('/system', static fn($rq, $rs) => $system()->show($rq, $rs));
        $admin->post('/system/migrate', static fn($rq, $rs) => $system()->migrate($rq, $rs));
        $admin->post('/system/retry-failed', static fn($rq, $rs) => $system()->retryFailed($rq, $rs));
        $admin->post('/system/backup', static fn($rq, $rs) => $system()->backup($rq, $rs))
            ->add(new RateLimit(static fn() => $services->rateLimiter(), 'admin-backup', 10, 3600, $services->clientIp()));
        $admin->post('/system/restore', static fn($rq, $rs) => $system()->restore($rq, $rs))
            ->add(new RateLimit(static fn() => $services->rateLimiter(), 'admin-restore', 5, 3600, $services->clientIp()));
    }
}
