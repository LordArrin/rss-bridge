<?php

declare(strict_types=1);

namespace RSSBridge\Middlewares;

use RSSBridge\Http\Request;
use RSSBridge\Http\Response;
use RSSBridge\Logger\Logger;
use RSSBridge\Utils\Url;

/**
 * Last-resort error boundary of the middleware chain.
 *
 * Status mapping is delegated to httpStatusFromException(), which guarantees
 * the per-feed contract required by FreshRSS: transient upstream problems are
 * reported as 502/504, unexpected local problems as 500, permanent client
 * errors keep their own 4xx code. 429 and 503 are never produced here and no
 * Retry-After header is ever attached, because FreshRSS converts those codes
 * into a domain-wide cooldown that would freeze every feed on this instance.
 * Upstream politeness is enforced server-side by the CacheMiddleware backoff
 * marker instead. Whole-instance unavailability is signalled separately by
 * MaintenanceMiddleware, the only place allowed to emit 503.
 */
final class ExceptionMiddleware implements Middleware
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Converts an uncaught throwable into a per-feed error response.
     * Client errors are logged at debug level, server errors at error level.
     */
    public function __invoke(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (\Throwable $e) {
            [$status, $headers] = \httpStatusFromException($e);

            if ($status < 500) {
                $this->logger->debug(sprintf(
                    'Exception in ExceptionMiddleware: %s (%s)',
                    Url::createSaneExceptionMessage($e),
                    $status
                ));
            } else {
                $this->logger->error('Exception in ExceptionMiddleware', ['e' => $e]);
            }

            return new Response(render(__DIR__ . '/../templates/exception.html.php', ['e' => $e]), $status, $headers);
        }
    }
}
