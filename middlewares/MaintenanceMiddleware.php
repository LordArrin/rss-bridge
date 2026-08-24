<?php

declare(strict_types=1);

namespace RSSBridge\Middlewares;

use Request;
use Response;
use RSSBridge\Configuration;

final class MaintenanceMiddleware implements Middleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        if ((bool) Configuration::getConfig('system', 'enable_maintenance_mode') === false) {
            return $next($request);
        }

        return new Response(render(__DIR__ . '/../templates/error.html.php', [
            'title' => '503 Service Unavailable',
            'message' => 'RSS-Bridge is down for maintenance.',
        ]), 503);
    }
}
