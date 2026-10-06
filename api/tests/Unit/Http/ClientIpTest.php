<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Http;

use ConsultDesk\Http\ClientIp;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ClientIpTest extends TestCase
{
    public function testUsesTheConnectingAddressAndGroupsIpv6By64Prefix(): void
    {
        $resolver = new ClientIp();

        self::assertSame('203.0.113.7', $resolver->of($this->request('203.0.113.7')));
        self::assertSame('2001:db8:1:2::/64', $resolver->of($this->request('2001:db8:1:2:aaaa:bbbb:cccc:dddd')));
        self::assertSame('2001:db8:1:2::/64', $resolver->of($this->request('2001:db8:1:2::1')));
        self::assertSame('unknown', $resolver->of($this->request('')));
    }

    public function testIgnoresForwardedHeadersUnlessTheProxyIsTrusted(): void
    {
        $untrusted = new ClientIp();
        $trusted = new ClientIp(['10.0.0.0/8', '192.0.2.10'], 'CF-Connecting-IP');

        $viaProxy = $this->request('10.1.2.3', ['CF-Connecting-IP' => '198.51.100.20']);
        self::assertSame('10.1.2.3', $untrusted->of($viaProxy));
        self::assertSame('198.51.100.20', $trusted->of($viaProxy));
        self::assertSame('198.51.100.20', $trusted->of($this->request('192.0.2.10', ['CF-Connecting-IP' => '198.51.100.20'])));

        $spoofed = $this->request('203.0.113.7', ['CF-Connecting-IP' => '198.51.100.20']);
        self::assertSame('203.0.113.7', $trusted->of($spoofed), 'a client cannot claim to be someone else');
        self::assertSame('10.1.2.3', $trusted->of($this->request('10.1.2.3', ['CF-Connecting-IP' => 'not-an-ip'])));
    }

    public function testForwardedForTakesTheRightmostUntrustedAddress(): void
    {
        $trusted = new ClientIp(['10.0.0.0/8'], 'X-Forwarded-For');

        self::assertSame('198.51.100.20', $trusted->of($this->request('10.0.0.5', ['X-Forwarded-For' => '1.2.3.4, 198.51.100.20, 10.0.0.9'])));
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $ip, array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/', ['REMOTE_ADDR' => $ip]);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }
}
