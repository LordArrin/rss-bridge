<?php

declare(strict_types=1);

namespace RSSBridge\Middlewares;

use Request;
use Response;

/**
 * Make sure that only strings are allowed in GET parameters
 */
final class SecurityMiddleware implements Middleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        foreach ($request->toArray() as $key => $value) {
            if (!is_string($value)) {
                return new Response(render(__DIR__ . '/../templates/error.html.php', [
                    'message' => "Query parameter \"$key\" is not a string.",
                ]), 400);
            }
        }

        return $next($request);
    }
}
