<?php

declare(strict_types=1);

namespace ConsultDesk\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Who is calling, for rate limiting.
 *
 * - The connecting address is used unless it is a configured trusted proxy (e.g. Cloudflare);
 *   only then is the configured client-IP header believed.
 * - IPv6 clients are grouped by /64, because one subscriber usually controls a whole /64.
 */
final class ClientIp
{
    private const UNKNOWN = 'unknown';

    /**
     * @param list<string> $trustedProxies IPs or CIDR ranges
     */
    public function __construct(
        private readonly array $trustedProxies = [],
        private readonly ?string $clientIpHeader = null,
    ) {}

    public function of(ServerRequestInterface $request): string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        if (!is_string($remote) || filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return self::UNKNOWN;
        }

        return self::bucket($this->resolve($request, $remote));
    }

    private function resolve(ServerRequestInterface $request, string $remote): string
    {
        if ($this->clientIpHeader === null || !$this->isTrusted($remote)) {
            return $remote;
        }

        // Walk the header right to left, skipping our own proxies; the first other address is the client.
        $candidates = array_reverse(array_map('trim', explode(',', $request->getHeaderLine($this->clientIpHeader))));
        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                return $remote;
            }
            if (!$this->isTrusted($candidate)) {
                return $candidate;
            }
        }

        return $remote;
    }

    private function isTrusted(string $ip): bool
    {
        foreach ($this->trustedProxies as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $ip, string $range): bool
    {
        $slash = strpos($range, '/');
        $subnet = $slash === false ? $range : substr($range, 0, $slash);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $prefix = $slash === false ? strlen($ipBin) * 8 : (int) substr($range, $slash + 1);

        return self::maskTo($ipBin, $prefix) === self::maskTo($subnetBin, $prefix);
    }

    private static function bucket(string $ip): string
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return self::UNKNOWN;
        }
        if (strlen($binary) === 4) {
            return $ip;
        }

        $network = inet_ntop(self::maskTo($binary, 64));

        return ($network === false ? $ip : $network) . '/64';
    }

    private static function maskTo(string $binary, int $prefix): string
    {
        $masked = '';
        foreach (str_split($binary) as $i => $byte) {
            $keep = max(0, min(8, $prefix - $i * 8));
            $masked .= chr(ord($byte) & (0xFF << (8 - $keep)) & 0xFF);
        }

        return $masked;
    }
}
