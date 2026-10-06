<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Catalog;

interface CatalogRepository
{
    /**
     * @return list<ProviderProfile> active providers in display order
     */
    public function activeProviders(): array;

    public function activeProvider(string $slug): ?ProviderProfile;

    /**
     * @return list<ServiceOffering> the provider's active services in display order
     */
    public function activeServices(int $providerId): array;

    public function activeService(int $providerId, string $slug): ?ServiceOffering;
}
