<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Http\ApiException;
use ConsultDesk\Infra\ImageStore;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * Serves an uploaded image. Names are random and never reused, so the response can be cached for good.
 */
final class MediaAction
{
    public function __construct(private readonly ImageStore $images) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $name = $args['name'] ?? '';
        $path = $this->images->path($name) ?? throw ApiException::notFound();
        $handle = fopen($path, 'rb') ?: throw ApiException::notFound();

        return $response
            ->withBody(new Stream($handle))
            ->withHeader('Content-Type', str_ends_with($name, '.webp') ? 'image/webp' : 'image/png')
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    }
}
