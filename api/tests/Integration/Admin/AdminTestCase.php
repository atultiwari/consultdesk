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
    protected const PASSWORD = 'correct horse battery staple';

    protected string $cookie = '';
    protected string $csrf = '';

    protected function createUser(string $email, string $role = 'owner', ?int $providerId = null): int
    {
        $id = Fixtures::user($this->pdo, $role, $providerId, $email);
        $statement = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $statement->execute([password_hash(self::PASSWORD, PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]), $id]);

        return $id;
    }

    /**
     * @return array{int, array<string, mixed>, \Psr\Http\Message\ResponseInterface}
     */
    protected function login(string $email, string $password = self::PASSWORD, string $ip = '203.0.113.7'): array
    {
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
