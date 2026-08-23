<?php

declare(strict_types=1);

namespace RSSBridge\Middlewares;

use Request;
use Response;

/**
 * Interface for all HTTP middlewares.
 *
 * Middlewares can:
 * - Modify the request before passing to next middleware/action
 * - Modify the response before returning
 * - Short-circuit the chain by returning early
 */
interface Middleware
{
    /**
     * @param Request $request The incoming request
     * @param callable $next The next middleware or final action handler
     * @return Response The response to return
     */
    public function __invoke(Request $request, callable $next): Response;
}
