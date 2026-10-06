<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Catalog;

/**
 * Public look-and-feel settings (settings key "site"), edited in the admin Branding panel (Phase 6).
 * Values are sanitised on the way out, because they end up in CSS and markup.
 */
final class SiteSettings
{
    public const PRESETS = ['neutral', 'he', 'vrl'];
    private const HEX = '/^#[0-9a-f]{6}$/';
    private const MAX_NAME = 80;

    private function __construct(
        public readonly string $orgName,
        public readonly string $preset,
        public readonly ?string $accent,
        public readonly ?string $accent2,
        public readonly ?string $logoUrl,
    ) {}

    /**
     * @param array<string, mixed> $stored
     */
    public static function fromStored(array $stored): self
    {
        $name = is_string($stored['org_name'] ?? null) ? trim($stored['org_name']) : '';
        $preset = is_string($stored['preset'] ?? null) && in_array($stored['preset'], self::PRESETS, true) ? $stored['preset'] : 'neutral';

        return new self(
            $name === '' ? 'ConsultDesk' : mb_substr($name, 0, self::MAX_NAME),
            $preset,
            self::hex($stored['accent'] ?? null),
            self::hex($stored['accent_2'] ?? null),
            self::logo($stored['logo_url'] ?? null),
        );
    }

    /**
     * @return array{org_name: string, preset: string, accent: ?string, accent_2: ?string, logo_url: ?string}
     */
    public function toArray(): array
    {
        return [
            'org_name' => $this->orgName,
            'preset' => $this->preset,
            'accent' => $this->accent,
            'accent_2' => $this->accent2,
            'logo_url' => $this->logoUrl,
        ];
    }

    private static function hex(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return preg_match(self::HEX, $value) === 1 ? $value : null;
    }

    private static function logo(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return preg_match('#^(https?://[^\s"\'<>]+|/[^\s"\'<>]*)$#i', $value) === 1 ? $value : null;
    }
}
