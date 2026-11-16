<?php

declare(strict_types=1);

namespace RSSBridge\Middlewares;

use RSSBridge\Http\Request;
use RSSBridge\Http\Response;

final class TokenAuthenticationMiddleware implements Middleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        $tokenRaw = getenv('AUTHENTICATION_TOKEN');
        $token = ($tokenRaw !== false && $tokenRaw !== '') ? $tokenRaw : '';

        if ($token === '') {
            return $next($request);
        }

        if ($request->get('action') === 'health') {
            return $next($request);
        }

        $requestToken = $request->get('token');

        if (is_string($requestToken) === false || $requestToken === '') {
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
