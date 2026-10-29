<?php

declare(strict_types=1);

namespace RSSBridge\Middlewares;

use RSSBridge\Http\Request;
use RSSBridge\Http\Response;
use RSSBridge\Configuration;

final class MaintenanceMiddleware implements Middleware
{
    /** How long (seconds) clients should wait before retrying during maintenance. */
    private const RETRY_AFTER = 1800;

    public function __invoke(Request $request, callable $next): Response
    {
        if ((bool) Configuration::getConfig('system', 'enable_maintenance_mode') === false) {
            return $next($request);
        }

        // HTTP 503 with Retry-After: FreshRSS treats this as a temporary
        // error and keeps the existing feed content until the next poll.
        return new Response(render(__DIR__ . '/../templates/error.html.php', [
            'title' => '503 Service Unavailable',
            'message' => 'RSS-Bridge is down for maintenance.',
        ]), 503, [
            'retry-after' => (string) self::RETRY_AFTER,
            'cache-control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'pragma' => 'no-cache',
            'expires' => gmdate('D, d M Y H:i:s') . ' GMT',
        ]);
    }
}
