<?php

declare(strict_types=1);

namespace RSSBridge\Middlewares;

use RSSBridge\Http\Request;
use RSSBridge\Http\Response;

/**
 * Maintenance mode middleware.
 *
 * Reads the MAINTENANCE environment variable (ON/OFF) set by /bin/load-env.
 * When enabled, returns HTTP 503 Service Unavailable with a Retry-After header
 * to signal clients (especially FreshRSS and other feed readers) to retry later.
 *
 * The health endpoint is exempt so container orchestrators keep seeing a
 * healthy instance during maintenance: the Docker HEALTHCHECK cannot send
 * credentials and must not flip the container to unhealthy just because an
 * operator enabled maintenance. Feed readers still receive 503 + Retry-After.
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

        // Health must stay reachable for orchestrators (same exemption as the
        // authentication middlewares), otherwise the container flips to
        // unhealthy and may be restarted while maintenance is intentional.
        if ($request->get('action') === 'health') {
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
