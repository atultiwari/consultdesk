<?php

declare(strict_types=1);

namespace ConsultDesk\Updates;

/**
 * One published version: its zip and the zip's signature.
 */
final class Release
{
    public function __construct(
        public readonly string $version,
        public readonly string $notes,
        public readonly ?string $publishedAt,
        public readonly string $zipUrl,
        public readonly string $signatureUrl,
        public readonly bool $prerelease,
        public readonly string $pageUrl = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'notes' => $this->notes,
            'published_at' => $this->publishedAt,
            'zip_url' => $this->zipUrl,
            'signature_url' => $this->signatureUrl,
            'prerelease' => $this->prerelease,
            'page_url' => $this->pageUrl,
        ];
    }

    /**
     * @param array<string, mixed> $a
     */
    public static function fromArray(array $a): ?self
    {
        foreach (['version', 'zip_url', 'signature_url'] as $key) {
            if (!is_string($a[$key] ?? null) || $a[$key] === '') {
                return null;
            }
        }

        return new self(
            (string) $a['version'],
            is_string($a['notes'] ?? null) ? $a['notes'] : '',
            is_string($a['published_at'] ?? null) ? $a['published_at'] : null,
            (string) $a['zip_url'],
            (string) $a['signature_url'],
            (bool) ($a['prerelease'] ?? false),
            is_string($a['page_url'] ?? null) ? $a['page_url'] : '',
        );
    }
}
