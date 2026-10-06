<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Admin\AdminUser;
use ConsultDesk\Admin\ProviderSettings;
use ConsultDesk\Admin\Role;
use ConsultDesk\Http\ApiException;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Looks things up on behalf of the signed-in person. Anything outside their scope is "not found",
 * so a provider cannot even learn which other ids exist.
 */
final class AdminScope
{
    public static function user(Request $request): AdminUser
    {
        return AdminAuthActions::session($request)->user;
    }

    /**
     * Organisation settings (users, branding, payments, system) are for owners only.
     */
    public static function owner(Request $request): AdminUser
    {
        $user = self::user($request);
        if ($user->role !== Role::Owner) {
            throw ApiException::forbidden();
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public static function provider(ProviderSettings $providers, Request $request, int $providerId): array
    {
        $provider = $providers->find($providerId);
        if ($provider === null || !self::user($request)->canManageProvider($providerId)) {
            throw ApiException::notFound();
        }

        return $provider;
    }
}
