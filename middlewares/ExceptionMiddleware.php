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

            $response = new Response(render(__DIR__ . '/../templates/exception.html.php', ['e' => $e]), $status, $headers);

            // A slow bridge/upstream that trips the HTTP timeout surfaces here
            // as an HttpException with code 0 / "cURL error" instead of a
            // proper response object. Tag it so CacheMiddleware recognises the
            // request as timed out: serve the last good cached copy when one
            // exists, otherwise answer 503 with Retry-After: 600 (10 minutes).
            // This keeps timeouts out of the "broken feed" category for
            // FreshRSS without changing any of the configured timeouts
            // themselves - some bridges are legitimately slow.
            if ($this->isTimeoutThrowable($e) === true) {
                return $response->withHeader('x-feed-timeout', '1');
            }

            return $response;
        }
    }

    private function isTimeoutThrowable(\Throwable $e): bool
    {
        if (($e instanceof \HttpException) === false) {
            return false;
        }

        // Rate limiting is not a timeout: it must keep its own 429 handling.
        if (($e instanceof \RateLimitException) === true) {
            return false;
        }

        $code = (int) $e->getCode();
        if (in_array($code, [408, 504], true) === true) {
            return true;
        }

        return str_contains($e->getMessage(), 'cURL error') === true;
    }
}
