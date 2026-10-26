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
            $code = $e->getCode();

            if ($e instanceof \HttpException) {
                $status = ($code >= 400 && $code <= 599) ? $code : 500;
            } else {
                $status = 500;
            }

            if ($status < 500) {
                $this->logger->debug(sprintf(
                    'Exception in ExceptionMiddleware: %s (%s)',
                    Url::createSaneExceptionMessage($e),
                    $status
                ));
            } else {
                $this->logger->error('Exception in ExceptionMiddleware', ['e' => $e]);
            }

            return new Response(render(__DIR__ . '/../templates/exception.html.php', ['e' => $e]), $status);
        }
    }
}
