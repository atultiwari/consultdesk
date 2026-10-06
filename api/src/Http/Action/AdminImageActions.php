<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Admin\ProviderSettings;
use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Domain\Catalog\SiteSettings;
use ConsultDesk\Http\JsonInput;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Validation\Input;
use ConsultDesk\Http\Validation\ValidationFailed;
use ConsultDesk\Infra\AuditLog;
use ConsultDesk\Infra\ImageStore;
use ConsultDesk\Infra\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Branding (owner only) and provider photos: the site's name, preset and colours, the logo, and each
 * provider's portrait.
 */
final class AdminImageActions
{
    private const SITE = 'site';
    private const MEDIA_PREFIX = '/api/media/';
    private const HEX = '/^#[0-9a-fA-F]{6}$/';

    public function __construct(
        private readonly Settings $settings,
        private readonly ImageStore $images,
        private readonly ProviderSettings $providers,
        private readonly AuditLog $audit,
    ) {}

    public function branding(Request $request, Response $response): Response
    {
        AdminScope::owner($request);

        return JsonResponse::success($response, $this->site());
    }

    public function saveBranding(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $input = new Input(JsonInput::decode($request));
        $name = $input->string('org_name', max: 80);
        $preset = $input->oneOf('preset', SiteSettings::PRESETS);
        $accents = [];
        foreach (['accent', 'accent_2'] as $field) {
            $value = $input->string($field, required: false, max: 7);
            if ($value !== null && preg_match(self::HEX, $value) !== 1) {
                $input->reject($field, 'Use a colour like #3b2e7e.');
            }
            $accents[$field] = $value === null ? null : strtolower($value);
        }
        $input->assertValid();

        $this->settings->put(self::SITE, [...$this->settings->get(self::SITE), 'org_name' => $name, 'preset' => $preset, ...$accents]);
        $this->audit->record(Actor::user($owner->id), 'admin.branding_updated', 'settings', null, ['preset' => $preset]);

        return JsonResponse::success($response, $this->site());
    }

    public function uploadLogo(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $name = $this->images->storeLogo(self::file($request));
        $this->replaceLogo(self::MEDIA_PREFIX . $name);
        $this->audit->record(Actor::user($owner->id), 'admin.logo_changed', 'settings', null);

        return JsonResponse::success($response, $this->site());
    }

    public function removeLogo(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $this->replaceLogo(null);
        $this->audit->record(Actor::user($owner->id), 'admin.logo_removed', 'settings', null);

        return JsonResponse::success($response, $this->site());
    }

    /**
     * @param array<string, string> $args
     */
    public function uploadPhoto(Request $request, Response $response, array $args): Response
    {
        $provider = AdminScope::provider($this->providers, $request, (int) ($args['id'] ?? 0));
        $name = $this->images->storePhoto(self::file($request));

        return $this->replacePhoto($request, $response, $provider, 'api/media/' . $name);
    }

    /**
     * @param array<string, string> $args
     */
    public function removePhoto(Request $request, Response $response, array $args): Response
    {
        $provider = AdminScope::provider($this->providers, $request, (int) ($args['id'] ?? 0));

        return $this->replacePhoto($request, $response, $provider, null);
    }

    /**
     * @param array<string, mixed> $provider
     */
    private function replacePhoto(Request $request, Response $response, array $provider, ?string $photoPath): Response
    {
        $this->providers->setPhoto((int) $provider['id'], $photoPath);
        $this->images->delete(self::mediaName($provider['photo_url'] ?? null));
        $this->audit->record(Actor::user(AdminScope::user($request)->id), $photoPath === null ? 'admin.photo_removed' : 'admin.photo_changed', 'provider', (int) $provider['id']);

        return JsonResponse::success($response, $this->providers->find((int) $provider['id']));
    }

    private function replaceLogo(?string $logoUrl): void
    {
        $stored = $this->settings->get(self::SITE);
        $this->settings->put(self::SITE, [...$stored, 'logo_url' => $logoUrl]);
        $this->images->delete(self::mediaName($stored['logo_url'] ?? null));
    }

    /**
     * @return array<string, mixed>
     */
    private function site(): array
    {
        return SiteSettings::fromStored($this->settings->get(self::SITE))->toArray();
    }

    /**
     * The stored file name behind a /api/media/... URL; null for anything else (e.g. an external logo).
     */
    private static function mediaName(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, self::MEDIA_PREFIX) ? substr($url, strlen(self::MEDIA_PREFIX)) : null;
    }

    /**
     * @throws ValidationFailed
     */
    private static function file(Request $request): UploadedFileInterface
    {
        $file = $request->getUploadedFiles()['file'] ?? null;

        return $file instanceof UploadedFileInterface ? $file : throw new ValidationFailed(['file' => 'Choose an image to upload.']);
    }
}
