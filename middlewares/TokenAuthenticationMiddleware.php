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
        if (!Configuration::getConfig('authentication', 'token')) {
            return $next($request);
        }

        $token = $request->get('token');

        if (!$token) {
            return new Response(render(__DIR__ . '/../templates/token.html.php', [
                'message' => 'Missing token',
                'token' => '',
            ]), 401);
        }

        if (!hash_equals(Configuration::getConfig('authentication', 'token'), $token)) {
            return new Response(render(__DIR__ . '/../templates/token.html.php', [
                'message' => 'Invalid token',
                'token' => $token,
            ]), 401);
        }

        $request = $request->withAttribute('token', $token);

        return $next($request);
    }
}
