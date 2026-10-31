<?php

declare(strict_types=1);

namespace RSSBridge\Middlewares;

use RSSBridge\Http\Request;
use RSSBridge\Http\Response;

/**
 * Maintenance mode middleware.
 *
 * Reads the MAINTENANCE environment variable (ON/OFF) set by /bin/load-env.
 * When enabled, returns HTTP 503 Service Unavailable with Retry-After header
 * to signal clients (especially FreshRSS and other feed readers) to retry later.
 *
 * Clients must respects the Retry-After header and will not retry until the
 * specified time has elapsed, preventing unnecessary polling during maintenance.
 */
final class MaintenanceMiddleware implements Middleware
{
    /**
     * How long (seconds) clients should wait before retrying during maintenance.
     * 3600 = 1 hour, 86400 = 24 hours.
     */
    private const RETRY_AFTER = 3600;

    public function __invoke(Request $request, callable $next): Response
    {
        // Read MAINTENANCE env var (set by /bin/load-env)
        // Accept ON/on/On (case-insensitive), default to OFF if not set
        $maintenanceMode = getenv('MAINTENANCE');
        if (strtoupper((string) $maintenanceMode) !== 'ON') {
            return $next($request);
        }

        // HTTP 503 with Retry-After: FreshRSS and other feed readers treat this
        // as a temporary error and will not retry until the specified time has
        // elapsed, preventing unnecessary polling during maintenance.
        return new Response(render(__DIR__ . '/../templates/error.html.php', [
            'title' => '503 Service Unavailable',
            'message' => 'RSS-Bridge is down for maintenance. Please try again later.',
        ]), 503, [
            'retry-after' => (string) self::RETRY_AFTER,
            'cache-control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'pragma' => 'no-cache',
            'expires' => gmdate('D, d M Y H:i:s', time() + self::RETRY_AFTER) . ' GMT',
        ]);
    }
}
