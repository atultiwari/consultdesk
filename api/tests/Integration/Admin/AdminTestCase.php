<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

use ConsultDesk\Tests\Integration\Http\ApiTestCase;
use ConsultDesk\Tests\Integration\Support\Fixtures;

/**
 * Admin API tests: sign in through the real endpoints and keep the session cookie and CSRF token.
 */
abstract class AdminTestCase extends ApiTestCase
{
    /** A throwaway password made fresh for each test, so none is written down in the code. */
    protected string $password = '';

    protected string $cookie = '';
    protected string $csrf = '';

    private string $media = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->password = self::throwawayPassword();
        $this->media = sys_get_temp_dir() . '/consultdesk-media-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->media . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->media)) {
            rmdir($this->media);
        }
        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    protected function extraEnv(): array
    {
        return ['MEDIA_PATH' => $this->media];
    }

    protected static function throwawayPassword(): string
    {
        return bin2hex(random_bytes(12));
    }

    /**
     * @return array{int, array<string, mixed>, \Psr\Http\Message\ResponseInterface}
     */
    protected function resetWith(string $token, string $newPassword): array
    {
        return $this->call('POST', '/api/admin/password/reset', ['path' => self::ADMIN_PATH, 'token' => $token, 'password' => $newPassword]);
    }

    /**
     * Sends a file as multipart form data, the way the admin panel uploads images.
     *
     * @return array{int, array<string, mixed>, \Psr\Http\Message\ResponseInterface}
     */
    protected function upload(string $uri, string $contents, string $filename = 'image.png', string $type = 'image/png'): array
    {
        $file = new \Slim\Psr7\UploadedFile((new \Slim\Psr7\Factory\StreamFactory())->createStream($contents), $filename, $type, strlen($contents));
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('POST', $uri, ['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('Content-Type', 'multipart/form-data; boundary=x')
            ->withHeader('Cookie', $this->cookie)
            ->withHeader('X-CSRF-Token', $this->csrf)
            ->withUploadedFiles(['file' => $file]);
        $response = \ConsultDesk\Http\AppFactory::create($this->services())->handle($request);
        $body = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($body) ? $body : [], $response];
    }

    /**
     * A real image made with GD, in the given format.
     */
    protected static function image(int $width, int $height, string $format = 'png'): string
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 64, 41, 122));
        ob_start();
        match ($format) {
            'jpeg' => imagejpeg($image),
            'gif' => imagegif($image),
            'webp' => imagewebp($image),
            default => imagepng($image),
        };

        return (string) ob_get_clean();
    }

    protected function createUser(string $email, string $role = 'owner', ?int $providerId = null): int
    {
        $id = Fixtures::user($this->pdo, $role, $providerId, $email);
        $statement = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $statement->execute([password_hash($this->password, PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]), $id]);

        return $id;
    }

    /**
     * @return array{int, array<string, mixed>, \Psr\Http\Message\ResponseInterface}
     */
    protected function login(string $email, ?string $password = null, string $ip = '203.0.113.7'): array
    {
        $password ??= $this->password;
        $result = $this->call('POST', '/api/admin/login', ['path' => self::ADMIN_PATH, 'email' => $email, 'password' => $password], $ip);
        [$status, $body, $response] = $result;
        if ($status === 200) {
            preg_match('/^([^=]+=[^;]+)/', $response->getHeaderLine('Set-Cookie'), $m);
            $this->cookie = $m[1] ?? '';
            $this->csrf = (string) ($body['data']['csrf_token'] ?? '');
        }

        return $result;
    }

    /**
     * Calls the admin API as the signed-in user.
     *
     * @param array<string, mixed>|null $json
     *
     * @return array{int, array<string, mixed>, \Psr\Http\Message\ResponseInterface}
     */
    protected function admin(string $method, string $uri, ?array $json = null, bool $withCsrf = true): array
    {
        $headers = ['Cookie' => $this->cookie];
        if ($withCsrf && $method !== 'GET') {
            $headers['X-CSRF-Token'] = $this->csrf;
        }

        return $this->call($method, $uri, $json ?? ($method === 'GET' ? null : []), headers: $headers);
    }
}
