<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Catalog;

/**
 * Public view of a provider. Contact and payment details are deliberately not exposed here.
 */
final class ProviderProfile
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $title,
        public readonly ?string $bio,
        public readonly ?string $photoPath,
        public readonly string $timezone,
        public readonly bool $acceptsUpi,
    ) {}

    /**
     * @return array{slug: string, name: string, title: ?string, bio: ?string, photo_url: ?string, timezone: string}
     */
    public function toPublicArray(string $appUrl): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'title' => $this->title,
            'bio' => $this->bio,
            'photo_url' => $this->photoPath === null ? null : $appUrl . '/' . ltrim($this->photoPath, '/'),
            'timezone' => $this->timezone,
        ];
    }
}
