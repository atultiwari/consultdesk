<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Http;

use ConsultDesk\Bootstrap\AppServices;
use ConsultDesk\Http\AppFactory;
use ConsultDesk\Infra\Config;
use ConsultDesk\Infra\FrozenClock;
use ConsultDesk\Tests\Integration\IntegrationTestCase;
use ConsultDesk\Tests\Support\ArrayMailer;
use ConsultDesk\Tests\Support\FakeGoogleApi;
use ConsultDesk\Tests\Support\FakeTelegramApi;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

abstract class ApiTestCase extends IntegrationTestCase
{
    protected const NOW = '2026-10-05T00:00Z';
    protected const APP_URL = 'https://book.example.test';
    protected const CRON_KEY = 'cron-key-placeholder-0123456789abcdef';
    protected const ADMIN_PATH = 'desk-7q2x-placeholder';

    protected ArrayMailer $mailer;
    protected ?FakeTelegramApi $telegram = null;
    protected ?FakeGoogleApi $google = null;
    private string $now = self::NOW;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new ArrayMailer();
    }

    protected function at(string $now): void
    {
        $this->now = $now;
    }

    protected function services(): AppServices
    {
        $config = Config::load('/nonexistent/config.php', [
            'APP_URL' => self::APP_URL,
            'APP_KEY' => 'base64:' . base64_encode(str_repeat('t', 32)),
            'CRON_KEY' => self::CRON_KEY,
            'DB_HOST' => 'unused',
            'DB_NAME' => 'unused',
            'DB_USER' => 'unused',
            'SMTP_HOST' => 'unused',
            'MAIL_FROM' => 'bookings@example.test',
            'ADMIN_PATH' => self::ADMIN_PATH,
            ...$this->extraEnv(),
        ]);

        return new AppServices($config, db: $this->database, clock: new FrozenClock($this->now), mailer: $this->mailer, telegramApi: $this->telegram, googleApi: $this->google);
    }

    /**
     * @return array<string, string>
     */
    protected function extraEnv(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     *
     * @return array{int, array<string, mixed>, ResponseInterface}
     */
    protected function call(string $method, string $uri, ?array $json = null, string $ip = '203.0.113.7', array $headers = []): array
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri, ['REMOTE_ADDR' => $ip]);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        if ($json !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($json, JSON_THROW_ON_ERROR)));
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = AppFactory::create($this->services())->handle($request);
        $body = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($body) ? $body : [], $response];
    }
}
