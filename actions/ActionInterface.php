<?php

declare(strict_types=1);

namespace RSSBridge\Actions;

use RSSBridge\Http\Request;
use RSSBridge\Http\Response;

/**
 * Interface for all HTTP actions in RSS-Bridge.
 *
 * All actions receive a Request and must return a Response.
 */
interface ActionInterface
{
    public function __invoke(Request $request): Response;
}
