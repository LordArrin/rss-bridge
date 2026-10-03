<?php

declare(strict_types=1);

namespace RSSBridge\Middlewares;

use Request;
use Response;
use RSSBridge\Configuration;

/**
 * HTTP Basic auth check
 */
final class BasicAuthMiddleware implements Middleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        if ((bool) Configuration::getConfig('authentication', 'enable') === false) {
            return $next($request);
        }

        // Health endpoint must stay reachable for container orchestrators,
        // which cannot send credentials.
        if ($request->get('action') === 'health') {
            return $next($request);
        }

        [$expectedUser, $password, $passwordHash] = $this->getCredentials();

        if ($password === '' && $passwordHash === '') {
            return new Response('The authentication password cannot be the empty string', 500);
        }

        [$user, $authPassword] = $this->getProvidedCredentials($request);

        $unauthorized = function (): Response {
            $html = render(__DIR__ . '/../templates/error.html.php', [
                'message' => 'Please authenticate in order to access this instance!',
            ]);
            return new Response($html, 401, ['WWW-Authenticate' => 'Basic realm="RSS-Bridge", charset="UTF-8"']);
        };

        if ($user === null || $authPassword === null) {
            return $unauthorized();
        }

        $userMatches = hash_equals($expectedUser, $user);
        $passwordMatches = $this->verifyPassword($authPassword, $password, $passwordHash);

        if ($userMatches === false || $passwordMatches === false) {
            return $unauthorized();
        }

        return $next($request);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [username, plaintext password, hashed password]
     */
    private function getCredentials(): array
    {
        $username = (string) Configuration::getConfig('authentication', 'username');
        $password = (string) Configuration::getConfig('authentication', 'password');
        $passwordHash = (string) Configuration::getConfig('authentication', 'password_hash');

        return [$username, $password, $passwordHash];
    }

    /**
     * Extract the Basic credentials from the request.
     *
     * PHP_AUTH_USER/PHP_AUTH_PW are only populated when the SAPI passes the
     * Authorization header through. Under some nginx/fastcgi or Apache setups
     * they are missing, so fall back to parsing the raw header and finally
     * REDIRECT_HTTP_AUTHORIZATION.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function getProvidedCredentials(Request $request): array
    {
        $user = $request->server('PHP_AUTH_USER');
        $password = $request->server('PHP_AUTH_PW');

        if ($user !== null && $password !== null) {
            return [$user, $password];
        }

        $header = $request->server('HTTP_AUTHORIZATION')
            ?? $request->server('REDIRECT_HTTP_AUTHORIZATION');

        if ($header === null || $header === '') {
            return [null, null];
        }

        if (preg_match('/^Basic\s+(.*)$/i', trim($header), $matches) === 1) {
            $decoded = base64_decode(trim($matches[1]), true);
            if ($decoded !== false) {
                $parts = explode(':', $decoded, 2);
                if (count($parts) === 2) {
                    return [$parts[0], $parts[1]];
                }
            }
        }

        return [null, null];
    }

    private function verifyPassword(string $provided, string $plainPassword, string $passwordHash): bool
    {
        if ($passwordHash !== '') {
            return password_verify($provided, $passwordHash);
        }

        return hash_equals($plainPassword, $provided);
    }
}
