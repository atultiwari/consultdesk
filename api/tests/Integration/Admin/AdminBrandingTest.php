<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

use ConsultDesk\Tests\Integration\Support\Fixtures;

final class AdminBrandingTest extends AdminTestCase
{
    private const MEDIA_URL = '#^/api/media/[0-9a-f]{32}\.(webp|png)$#';

    public function testOwnersChangeTheSiteLookAndTheSiteShowsIt(): void
    {
        Fixtures::provider($this->pdo, ['slug' => 'demo']);
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        [$status, $saved] = $this->admin('PUT', '/api/admin/branding', ['org_name' => 'VRL Academy', 'preset' => 'vrl', 'accent' => '#3B2E7E', 'accent_2' => null]);
        self::assertSame(200, $status);
        self::assertSame(['org_name' => 'VRL Academy', 'preset' => 'vrl', 'accent' => '#3b2e7e', 'accent_2' => null, 'logo_url' => null], $saved['data']);
        self::assertSame('VRL Academy', $this->call('GET', '/api/site')[1]['data']['org_name']);

        [$bad, $errors] = $this->admin('PUT', '/api/admin/branding', ['org_name' => '', 'preset' => 'rainbow', 'accent' => 'red']);
        self::assertSame(422, $bad);
        self::assertSame(['org_name', 'preset', 'accent'], array_keys($errors['error']['fields']));
        self::assertSame(1, (int) (self::column($this->pdo, "SELECT COUNT(*) FROM audit_log WHERE action = 'admin.branding_updated'")[0] ?? 0));
    }

    public function testOnlyOwnersChangeBranding(): void
    {
        $this->createUser('admin@example.test', 'admin');
        $this->login('admin@example.test');

        self::assertSame(403, $this->admin('PUT', '/api/admin/branding', ['org_name' => 'X', 'preset' => 'neutral'])[0]);
        self::assertSame(403, $this->upload('/api/admin/branding/logo', self::image(10, 10))[0]);
    }

    public function testLogosAreReencodedResizedAndServed(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        [$status, $body] = $this->upload('/api/admin/branding/logo', self::image(2400, 600, 'jpeg'), 'logo.jpg', 'image/jpeg');
        self::assertSame(200, $status, json_encode($body) ?: '');
        $url = (string) $body['data']['logo_url'];
        self::assertMatchesRegularExpression(self::MEDIA_URL, $url);
        self::assertSame($url, $this->call('GET', '/api/site')[1]['data']['logo_url']);

        $response = $this->call('GET', $url)[2];
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('image/', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertStringContainsString('immutable', $response->getHeaderLine('Cache-Control'));
        $size = getimagesizefromstring((string) $response->getBody());
        self::assertSame([1200, 300], [$size[0] ?? 0, $size[1] ?? 0], 'scaled down to fit 1200×400');

        [, $replaced] = $this->upload('/api/admin/branding/logo', self::image(100, 100));
        self::assertNotSame($url, $replaced['data']['logo_url']);
        self::assertSame(404, $this->call('GET', $url)[0], 'the old file is removed');

        self::assertNull($this->admin('DELETE', '/api/admin/branding/logo')[1]['data']['logo_url']);
        self::assertSame(404, $this->call('GET', (string) $replaced['data']['logo_url'])[0]);
    }

    public function testOnlyRealPngJpegOrWebpImagesAreAccepted(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        foreach ([
            ['<?php echo "hi"; ?>', 'logo.png', 'image/png'],
            [self::image(20, 20, 'gif'), 'logo.gif', 'image/gif'],
            ['GIF89a' . str_repeat('x', 100), 'logo.png', 'image/png'],
            [str_repeat("\0", 3 * 1024 * 1024), 'huge.png', 'image/png'],
        ] as [$contents, $name, $type]) {
            [$status, $body] = $this->upload('/api/admin/branding/logo', $contents, $name, $type);
            self::assertSame([422, 'file'], [$status, array_key_first($body['error']['fields'] ?? [])], $name);
        }
        self::assertSame([], glob($this->mediaDir() . '/*') ?: []);
    }

    public function testProvidersUploadTheirOwnPhoto(): void
    {
        $demo = Fixtures::provider($this->pdo, ['slug' => 'demo']);
        $other = Fixtures::provider($this->pdo, ['slug' => 'other']);
        $this->createUser('demo@example.test', 'provider', $demo);
        $this->login('demo@example.test');

        [$status, $body] = $this->upload("/api/admin/providers/{$demo}/photo", self::image(1600, 1000, 'webp'), 'me.webp', 'image/webp');
        self::assertSame(200, $status);
        self::assertSame(self::APP_URL . $body['data']['photo_url'], $this->call('GET', '/api/providers/demo')[1]['data']['provider']['photo_url']);
        $size = getimagesizefromstring((string) $this->call('GET', (string) $body['data']['photo_url'])[2]->getBody());
        self::assertSame([600, 600], [$size[0] ?? 0, $size[1] ?? 0], 'cropped to a square, at most 600 px');

        self::assertSame(404, $this->upload("/api/admin/providers/{$other}/photo", self::image(10, 10))[0]);
        self::assertNull($this->admin('DELETE', "/api/admin/providers/{$demo}/photo")[1]['data']['photo_url']);
    }

    public function testUnknownOrOddMediaNamesAreNotFound(): void
    {
        foreach (['/api/media/' . str_repeat('a', 32) . '.webp', '/api/media/..%2F..%2Fconfig.php', '/api/media/abc.php'] as $url) {
            self::assertSame(404, $this->call('GET', $url)[0], $url);
        }
    }

    private function mediaDir(): string
    {
        return $this->services()->config->mediaPath;
    }
}
