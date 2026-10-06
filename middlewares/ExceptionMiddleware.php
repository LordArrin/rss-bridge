<?php

declare(strict_types=1);

namespace RSSBridge\Middlewares;

use ClientException;
use Logger;
use RateLimitException;
use Request;
use Response;

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
            // Preserve the HTTP semantics of known exceptions instead of
            // flattening everything to 500. RSS readers treat a hard 500 as
            // "feed is broken" and back off aggressively; returning the real
            // status (429/503 for rate limits and upstream outages) keeps
            // transient failures recoverable.
            $code = $e->getCode();
            if ($e instanceof HttpException || $e instanceof RateLimitException) {
                $status = ($code >= 400 && $code <= 599) ? $code : 500;
            } elseif ($e instanceof ClientException) {
                $status = ($code >= 400 && $code < 500) ? $code : 400;
            } else {
                $status = 500;
            }

            if ($status < 500) {
                $this->logger->debug(sprintf(
                    'Exception in ExceptionMiddleware: %s (%s)',
                    create_sane_exception_message($e),
                    $status
                ));
            } else {
                $this->logger->error('Exception in ExceptionMiddleware', ['e' => $e]);
            }

            return new Response(render(__DIR__ . '/../templates/exception.html.php', ['e' => $e]), $status);
        }
    }
}
