<?php

declare(strict_types=1);

namespace RSSBridge\Middlewares;

use RSSBridge\Http\Request;
use RSSBridge\Http\Response;
use RSSBridge\Logger\Logger;
use RSSBridge\Utils\Url;

final class ExceptionMiddleware implements Middleware
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function __invoke(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (\Throwable $e) {
            // Map transient failures onto HTTP codes that feed readers such
            // as FreshRSS understand: 429 (rate limit) and 503 (temporary
            // unavailability), always with Retry-After. Permanent problems
            // keep their original status; anything unexpected is a 500.
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
