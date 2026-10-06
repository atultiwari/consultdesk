<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Catalog;

use ConsultDesk\Domain\Booking\PaymentMethod;
use ConsultDesk\Infra\Settings;
use ConsultDesk\Payments\PaymentSwitches;
use PDO;

final class PdoCatalog implements CatalogRepository
{
    private const PROVIDER_COLUMNS = "id, slug, name, title, bio, photo_path, timezone, upi_vpa,
        EXISTS (SELECT 1 FROM payment_gateways g WHERE g.gateway = 'razorpay' AND g.active = 1
            AND (g.provider_id = providers.id OR g.provider_id IS NULL)) AS razorpay_ready";
    private const SERVICE_COLUMNS = 'id, provider_id, slug, title, tagline, description, audience, duration_min,
        price_minor, currency, requires_approval, payment_methods, questions';

    public function __construct(private readonly PDO $pdo) {}

    public function activeProviders(): array
    {
        $switches = $this->switches();

        return array_map(
            static fn(array $r): ProviderProfile => self::provider($r, $switches),
            $this->rows('SELECT ' . self::PROVIDER_COLUMNS . ' FROM providers WHERE active = 1 ORDER BY sort_order, name', []),
        );
    }

    public function activeProvider(string $slug): ?ProviderProfile
    {
        $rows = $this->rows('SELECT ' . self::PROVIDER_COLUMNS . ' FROM providers WHERE slug = :slug AND active = 1', ['slug' => $slug]);

        return $rows === [] ? null : self::provider($rows[0], $this->switches());
    }

    public function siteSettings(): SiteSettings
    {
        $rows = $this->rows("SELECT `value` FROM settings WHERE `key` = 'site'", []);
        $stored = $rows === [] ? [] : json_decode((string) $rows[0]['value'], true);

        return SiteSettings::fromStored(is_array($stored) ? $stored : []);
    }

    public function activeServices(int $providerId): array
    {
        return array_map(self::service(...), $this->rows(
            'SELECT ' . self::SERVICE_COLUMNS . ' FROM services WHERE provider_id = :provider AND active = 1 ORDER BY sort_order, id',
            ['provider' => $providerId],
        ));
    }

    public function activeService(int $providerId, string $slug): ?ServiceOffering
    {
        $rows = $this->rows(
            'SELECT ' . self::SERVICE_COLUMNS . ' FROM services WHERE provider_id = :provider AND slug = :slug AND active = 1',
            ['provider' => $providerId, 'slug' => $slug],
        );

        return $rows === [] ? null : self::service($rows[0]);
    }

    private function switches(): PaymentSwitches
    {
        return PaymentSwitches::load(new Settings($this->pdo));
    }

    /**
     * @param array<string, scalar> $params
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return array_values(array_filter($statement->fetchAll(PDO::FETCH_ASSOC), 'is_array'));
    }

    /**
     * @param array<string, mixed> $r
     */
    private static function provider(array $r, PaymentSwitches $switches): ProviderProfile
    {
        return new ProviderProfile(
            (int) $r['id'],
            (string) $r['slug'],
            (string) $r['name'],
            self::nullable($r['title']),
            self::nullable($r['bio']),
            self::nullable($r['photo_path']),
            (string) $r['timezone'],
            $switches->upiEnabled && self::nullable($r['upi_vpa']) !== null,
            $switches->razorpayEnabled && (bool) $r['razorpay_ready'],
        );
    }

    /**
     * @param array<string, mixed> $r
     */
    private static function service(array $r): ServiceOffering
    {
        $methods = json_decode((string) $r['payment_methods'], true, 4, JSON_THROW_ON_ERROR);

        return new ServiceOffering(
            id: (int) $r['id'],
            providerId: (int) $r['provider_id'],
            slug: (string) $r['slug'],
            title: (string) $r['title'],
            tagline: self::nullable($r['tagline']),
            description: self::nullable($r['description']),
            audience: self::nullable($r['audience']),
            durationMinutes: (int) $r['duration_min'],
            priceMinor: (int) $r['price_minor'],
            currency: (string) $r['currency'],
            requiresApproval: (bool) $r['requires_approval'],
            paymentMethods: array_values(array_filter(array_map(
                static fn(mixed $m): ?PaymentMethod => is_string($m) ? PaymentMethod::tryFrom($m) : null,
                is_array($methods) ? $methods : [],
            ))),
            questions: QuestionSet::fromJson((string) $r['questions']),
        );
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
