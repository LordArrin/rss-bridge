<?php

declare(strict_types=1);

namespace RSSBridge\Middlewares;

use Configuration;
use Request;
use Response;

/**
 * HTTP Basic auth check
 */
final class BasicAuthMiddleware implements Middleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        if (!Configuration::getConfig('authentication', 'enable')) {
            return $next($request);
        }

        if (Configuration::getConfig('authentication', 'password') === '') {
            return new Response('The authentication password cannot be the empty string', 500);
        }

        $user = $request->server('PHP_AUTH_USER');
        $password = $request->server('PHP_AUTH_PW');

        if ($user === null || $password === null) {
            $html = render(__DIR__ . '/../templates/error.html.php', [
                'message' => 'Please authenticate in order to access this instance!',
            ]);
            return new Response($html, 401, ['WWW-Authenticate' => 'Basic realm="RSS-Bridge"']);
        }

        if (
            (Configuration::getConfig('authentication', 'username') !== $user)
            || (!hash_equals(Configuration::getConfig('authentication', 'password'), $password))
        ) {
            $html = render(__DIR__ . '/../templates/error.html.php', [
                'message' => 'Please authenticate in order to access this instance!',
            ]);
            return new Response($html, 401, ['WWW-Authenticate' => 'Basic realm="RSS-Bridge"']);
        }

        return $next($request);
    }
}
