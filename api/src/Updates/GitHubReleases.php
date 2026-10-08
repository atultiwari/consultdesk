<?php

declare(strict_types=1);

namespace ConsultDesk\Updates;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Throwable;

/**
 * Releases of the public GitHub repository: the zip and its .sig attached to each release.
 */
final class GitHubReleases implements ReleaseSource
{
    private const API = 'https://api.github.com/repos/%s/releases?per_page=10';
    /** Downloads may only come from GitHub. */
    private const HOSTS = ['github.com', 'api.github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $repository,
        private readonly string $userAgent,
    ) {}

    public function latest(bool $includePrereleases): ?Release
    {
        try {
            $response = $this->http->request('GET', sprintf(self::API, $this->repository), [
                RequestOptions::HEADERS => ['Accept' => 'application/vnd.github+json', 'User-Agent' => $this->userAgent],
                RequestOptions::TIMEOUT => 15,
                RequestOptions::HTTP_ERRORS => false,
            ]);
        } catch (Throwable $e) {
            throw new UpdateFailed('Couldn’t reach GitHub to check for updates. Try again later.', 0, $e);
        }
        if ($response->getStatusCode() !== 200) {
            throw new UpdateFailed(sprintf('GitHub answered %d when checking for updates. Try again later.', $response->getStatusCode()));
        }
        $releases = json_decode((string) $response->getBody(), true);
        if (!is_array($releases)) {
            throw new UpdateFailed('GitHub sent an unexpected answer.');
        }
        foreach ($releases as $r) {
            if (!is_array($r) || ($r['draft'] ?? true)) {
                continue;
            }
            $version = ltrim((string) ($r['tag_name'] ?? ''), 'v');
            // A version like 1.0.0-beta.1 is a pre-release whatever the release's flag says.
            $prerelease = (bool) ($r['prerelease'] ?? false) || str_contains($version, '-');
            if ($prerelease && !$includePrereleases) {
                continue;
            }
            $zip = null;
            $sig = null;
            foreach (is_array($r['assets'] ?? null) ? $r['assets'] : [] as $asset) {
                $name = (string) ($asset['name'] ?? '');
                $url = (string) ($asset['browser_download_url'] ?? '');
                if (preg_match('/^consultdesk-[0-9][0-9A-Za-z.\-]*\.zip$/', $name) === 1) {
                    $zip = $url;
                } elseif (preg_match('/^consultdesk-[0-9][0-9A-Za-z.\-]*\.zip\.sig$/', $name) === 1) {
                    $sig = $url;
                }
            }
            if ($zip === null || $sig === null) {
                continue; // releases before signing (0.8.0) can't be installed from here
            }

            return new Release(
                $version,
                mb_substr((string) ($r['body'] ?? ''), 0, 20_000),
                is_string($r['published_at'] ?? null) ? $r['published_at'] : null,
                $zip,
                $sig,
                $prerelease,
                Release::safePageUrl((string) ($r['html_url'] ?? '')),
            );
        }

        return null;
    }

    public function download(string $url, string $path, int $maxBytes): void
    {
        if (!self::allowed($url)) {
            throw new UpdateFailed('That download isn’t from GitHub.');
        }
        try {
            $response = $this->http->request('GET', $url, [
                RequestOptions::HEADERS => ['User-Agent' => $this->userAgent, 'Accept' => 'application/octet-stream'],
                RequestOptions::TIMEOUT => 120,
                RequestOptions::SINK => $path,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::ALLOW_REDIRECTS => [
                    'max' => 5,
                    'protocols' => ['https'],
                    'on_redirect' => static function ($request, $response, $uri): void {
                        if (!self::allowed((string) $uri)) {
                            throw new UpdateFailed('The download was redirected away from GitHub.');
                        }
                    },
                ],
                // Enforced while downloading, not only from Content-Length.
                RequestOptions::PROGRESS => static function (int $expected, int $downloaded) use ($maxBytes): void {
                    if ($downloaded > $maxBytes) {
                        throw new UpdateFailed('The update is unexpectedly large.');
                    }
                },
                RequestOptions::ON_HEADERS => static function ($response) use ($maxBytes): void {
                    if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                        throw new UpdateFailed('The update is unexpectedly large.');
                    }
                },
            ]);
        } catch (Throwable $e) {
            // Guzzle wraps what the callbacks above throw; show their reason, not a generic one.
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof UpdateFailed) {
                    throw $cause;
                }
            }
            throw new UpdateFailed('The download didn’t finish. Try again later.', 0, $e);
        }
        if ($response->getStatusCode() !== 200 || !is_file($path) || filesize($path) > $maxBytes) {
            throw new UpdateFailed('The download didn’t finish. Try again later.');
        }
    }

    private static function allowed(string $url): bool
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? '') === 'https' && in_array(strtolower((string) ($parts['host'] ?? '')), self::HOSTS, true);
    }
}
