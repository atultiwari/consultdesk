<?php

declare(strict_types=1);

namespace ConsultDesk\Http;

use Psr\Http\Message\ServerRequestInterface;

final class ClientIp
{
    /**
     * The connecting address. Forwarded headers are ignored because they are trivially spoofed;
     * a deployment behind a trusted proxy (e.g. Cloudflare) needs explicit configuration later.
     */
    public static function of(ServerRequestInterface $request): string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return is_string($ip) && $ip !== '' ? $ip : 'unknown';
    }
}
