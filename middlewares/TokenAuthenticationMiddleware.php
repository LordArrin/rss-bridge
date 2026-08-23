<?php

declare(strict_types=1);

namespace RSSBridge\Middlewares;

use Configuration;
use Request;
use Response;

final class TokenAuthenticationMiddleware implements Middleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        $token = Configuration::getConfig('authentication', 'token');
        if ((bool) $token === false) {
            return $next($request);
        }

        $requestToken = $request->get('token');

        if ((bool) $requestToken === false) {
            return new Response(render(__DIR__ . '/../templates/token.html.php', [
                'message' => 'Missing token',
                'token' => '',
            ]), 401);
        }

        if (hash_equals($token, $requestToken) === false) {
            return new Response(render(__DIR__ . '/../templates/token.html.php', [
                'message' => 'Invalid token',
                'token' => $requestToken,
            ]), 401);
        }

        $request = $request->withAttribute('token', $requestToken);
        return $next($request);
    }
}
