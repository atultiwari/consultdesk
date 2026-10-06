<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

/**
 * A signed-in person and what they may touch.
 *
 * - Owner: everything, including organisation settings, users and payment gateways (Phase 6b/7).
 * - Admin: every provider and booking.
 * - Provider: only their own provider profile, services, availability and bookings.
 */
final class AdminUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly ?string $name,
        public readonly Role $role,
        public readonly ?int $providerId,
    ) {}

    public function canManageProvider(int $providerId): bool
    {
        return $this->role !== Role::Provider || $this->providerId === $providerId;
    }

    /** Organisation-wide things: every provider, org-wide closures, creating providers. */
    public function isStaff(): bool
    {
        return $this->role === Role::Owner || $this->role === Role::Admin;
    }

    /**
     * @return array{id: int, email: string, name: ?string, role: string, provider_id: ?int}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'name' => $this->name, 'role' => $this->role->value, 'provider_id' => $this->providerId];
    }
}
