<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Notify;

use ConsultDesk\Infra\MailConfig;
use ConsultDesk\Notify\Mail\EmailMessage;
use ConsultDesk\Notify\Mail\PhpMailerMailer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Sends through a real SMTP server (Mailpit) and reads the result back from Mailpit's API.
 * Skipped unless SMTP_HOST and MAILPIT_API are set (docker compose and CI set both).
 */
final class PhpMailerMailerTest extends TestCase
{
    private string $api = '';

    protected function setUp(): void
    {
        $this->api = (string) getenv('MAILPIT_API');
        if ($this->api === '' || (string) getenv('SMTP_HOST') === '') {
            self::markTestSkipped('Set SMTP_HOST, SMTP_PORT and MAILPIT_API to run the SMTP test.');
        }
        $this->http('DELETE', '/api/v1/messages');
    }

    public function testDeliversTextAndHtmlWithReplyTo(): void
    {
        $this->mailer((int) getenv('SMTP_PORT'))->send(new EmailMessage(
            ['asha@example.test', 'second@example.test'],
            'Confirmed: Thesis guidance (CD-7F3K)',
            "Plain text ₹1,499\n",
            '<html><body><p>HTML ₹1,499</p></body></html>',
            'provider@example.test',
        ));

        $list = $this->http('GET', '/api/v1/messages');
        self::assertSame(1, $list['total'] ?? null);
        $summary = $list['messages'][0];
        self::assertSame('Confirmed: Thesis guidance (CD-7F3K)', $summary['Subject']);
        self::assertSame(['asha@example.test', 'second@example.test'], array_column($summary['To'], 'Address'));
        self::assertSame('bookings@example.test', $summary['From']['Address']);

        $message = $this->http('GET', '/api/v1/message/' . $summary['ID']);
        self::assertStringContainsString('Plain text ₹1,499', $message['Text']);
        self::assertStringContainsString('HTML ₹1,499', $message['HTML']);
        self::assertSame('provider@example.test', $message['ReplyTo'][0]['Address']);
    }

    public function testConnectionFailuresBecomeRuntimeExceptionsWithoutSecrets(): void
    {
        try {
            $this->mailer(1)->send(new EmailMessage(['asha@example.test'], 'x', 'x', '<html></html>'));
            self::fail('Expected the send to fail.');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString('smtp-secret', $e->getMessage());
        }
    }

    private function mailer(int $port): PhpMailerMailer
    {
        return new PhpMailerMailer(new MailConfig(
            host: (string) getenv('SMTP_HOST'),
            port: $port,
            username: null,
            password: 'smtp-secret',
            encryption: null,
            fromEmail: 'bookings@example.test',
            fromName: 'ConsultDesk Test',
        ), timeoutSeconds: 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function http(string $method, string $path): array
    {
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 5]]);
        $body = file_get_contents(rtrim($this->api, '/') . $path, false, $context);
        if ($body === false) {
            throw new RuntimeException("Mailpit API unreachable at {$this->api}");
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
