<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Admin\AdminSession;
use ConsultDesk\Admin\AuthService;
use ConsultDesk\Admin\PasswordResets;
use ConsultDesk\Admin\Passwords;
use ConsultDesk\Http\AdminCookie;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\ClientIp;
use ConsultDesk\Http\JsonInput;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Middleware\AdminAuth;
use ConsultDesk\Http\Validation\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

/**
 * Sign-in, sign-out and password reset. Everything that works without a session also needs the
 * secret admin path, so these endpoints answer nothing useful to someone who doesn't know it.
 */
final class AdminAuthActions
{
    public function __construct(
        private readonly ?string $adminPath,
        private readonly AuthService $auth,
        private readonly PasswordResets $resets,
        private readonly AdminCookie $cookie,
        private readonly ClientIp $clientIp,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function entry(Request $request, Response $response, array $args): Response
    {
        $this->assertPath($args['path'] ?? '');

        return JsonResponse::success($response, ['ok' => true]);
    }

    public function login(Request $request, Response $response): Response
    {
        $input = $this->guarded($request);
        $email = $input->string('email', max: 254);
        $password = $input->secret('password', max: Passwords::MAX_LENGTH);
        $input->assertValid();

        $session = $this->auth->login((string) $email, (string) $password, $this->clientIp->of($request), $request->getHeaderLine('User-Agent'));

        return $this->cookie->set(JsonResponse::success($response, self::sessionData($session)), $session->token);
    }

    public function me(Request $request, Response $response): Response
    {
        return JsonResponse::success($response, self::sessionData(self::session($request)));
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout(self::session($request));

        return $this->cookie->clear(JsonResponse::success($response, ['ok' => true]));
    }

    public function logoutAll(Request $request, Response $response): Response
    {
        $this->auth->logoutEverywhere(self::session($request));

        return $this->cookie->clear(JsonResponse::success($response, ['ok' => true]));
    }

    public function forgot(Request $request, Response $response): Response
    {
        $input = $this->guarded($request);
        $email = $input->email('email');
        $input->assertValid();

        $this->resets->request((string) $email);

        return JsonResponse::success($response, ['ok' => true]);
    }

    public function reset(Request $request, Response $response): Response
    {
        $input = $this->guarded($request);
        $token = $input->string('token', max: 64);
        $password = $input->secret('password', max: Passwords::MAX_LENGTH);
        if ($password !== null && !Passwords::acceptable($password)) {
            $input->reject('password', sprintf('Use at least %d characters.', Passwords::MIN_LENGTH));
        }
        $input->assertValid();

        $this->resets->reset((string) $token, (string) $password);

        return JsonResponse::success($response, ['ok' => true]);
    }

    public static function session(Request $request): AdminSession
    {
        $session = $request->getAttribute(AdminAuth::ATTRIBUTE);

        return $session instanceof AdminSession ? $session : throw new RuntimeException('Admin route without AdminAuth.');
    }

    private function guarded(Request $request): Input
    {
        $input = JsonInput::from($request);
        $this->assertPath((string) $input->string('path', max: 64));

        return $input;
    }

    private function assertPath(string $path): void
    {
        if ($this->adminPath === null || !hash_equals($this->adminPath, $path)) {
            throw ApiException::notFound();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function sessionData(AdminSession $session): array
    {
        return ['user' => $session->user->toArray(), 'csrf_token' => $session->csrfToken];
    }
}
