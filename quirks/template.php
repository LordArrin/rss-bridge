<?php

declare(strict_types=1);

/**
 * HTML template rendering utilities.
 *
 * This file provides a simple two-level template rendering system
 * for generating HTML pages in RSS-Bridge. Templates are plain PHP
 * files that receive variables via extract() and output HTML.
 *
 * The rendering process:
 * 1. render() wraps content in base.html.php layout
 * 2. render_template() executes a single template file with context
 *
 * Loading mechanism: registered in composer.json "files" autoload,
 * so the functions are available globally without any require.
 */

/**
 * Render a template wrapped in the base layout.
 *
 * This is the main entry point for rendering full HTML pages. It:
 * - Converts legacy context messages (if any) to the unified system
 * - Adds the admin-defined system message via the unified notification API
 * - Adds debug information in dev mode via the unified notification API
 * - Renders the content template
 * - Wraps it in base.html.php layout
 *
 * Notifications are produced through the unified notification system
 * (lib/notifications.php) and rendered by render_notifications() inside
 * base.html.php.
 *
 * @param string $template Template filename (e.g. 'frontpage.html.php').
 * @param array<string, mixed> $context Variables to pass to the template.
 * @return string The complete rendered HTML page.
 * @throws \Exception If attempting to render base.html.php into itself.
 */
function render(string $template, array $context = []): string
{
    if ($template === 'base.html.php') {
        throw new \Exception('Do not render base.html.php into itself');
    }

    // Backwards compatibility: convert any legacy messages passed in the
    // render context into unified notifications before removing them from
    // the context so the old alert-* markup is never used again.
    $legacyMessages = (array) ($context['messages'] ?? []);
    unset($context['messages']);

    foreach ($legacyMessages as $message) {
        if (is_array($message) === false) {
            continue;
        }
        $level = strtolower((string) ($message['level'] ?? 'info'));
        $body = strip_tags((string) ($message['body'] ?? ''));
        switch ($level) {
            case 'warning':
                notify_warning($body, null, 'admin');
                break;
            case 'error':
                notify_error($body, null, 'admin');
                break;
            default:
                notify_info($body, null, 'admin');
                break;
        }
    }

    // Admin-defined system messages from environment variables (unified notifications).
    $systemMessages = get_admin_system_messages();
    if ($systemMessages !== []) {
        $adminLinks = get_admin_contact_links();
        foreach ($systemMessages as $sm) {
            $body = $sm['body'];
            $links = $adminLinks;
            if (($sm['url'] ?? '') !== '') {
                // Append the message link as a {N} placeholder resolved by the renderer.
                $links[] = ['label' => $sm['url_label'], 'url' => $sm['url']];
                $body .= sprintf(' {%d}', count($links) - 1);
            }
            $builder = match ($sm['level']) {
                'warning' => 'notify_warning',
                'error'   => 'notify_error',
                default   => 'notify_info',
            };
            $builder($body, $sm['title'], 'admin', [], $links);
        }
    }

    // Debug information in dev environment (unified notifications)
    $appEnv = getenv('APP_ENV');
    if ($appEnv !== false && $appEnv === 'DEV') {
        notify_warning('System environment: dev', 'Debug mode', 'admin');
        $cacheTypeRaw = getenv('CACHE_TYPE');
        $cacheType = ($cacheTypeRaw !== false && $cacheTypeRaw !== '') ? (string) $cacheTypeRaw : 'file';
        notify_info(
            sprintf('Cache type: %s', $cacheType),
            'Debug info',
            'admin'
        );
    }

    $context['page'] = render_template($template, $context);
    return render_template('base.html.php', $context);
}

/**
 * Collect the admin-defined messages from environment variables and normalize
 * them into plain-data descriptors ready for the unified notification system.
 *
 * Priority:
 *   1. SYSTEM_MESSAGES (JSON array) - if present and valid, single message
 *      variables are ignored to prevent duplication
 *   2. SYSTEM_MESSAGE + SYSTEM_MESSAGE_* - fallback if SYSTEM_MESSAGES is empty
 *
 * Supported environment variables:
 *   SYSTEM_MESSAGE       = "Text"
 *   SYSTEM_MESSAGE_URL   = "https://..."
 *   SYSTEM_MESSAGE_TITLE = "Heading"
 *   SYSTEM_MESSAGE_LEVEL = "info|warning|error"
 *   SYSTEM_MESSAGES      = '[{"text":"...","level":"warning"}]' (JSON array)
 *
 * Text fields are treated as PLAIN TEXT (the notification renderer escapes
 * them); any HTML coming from configuration is stripped here so that admins
 * cannot inject markup.
 *
 * @return array<int, array{body: string, url: string, title: string, level: string, url_label: string}>
 */
function get_admin_system_messages(): array
{
    $result = [];

    // 1. Check for multi-message JSON environment variable FIRST
    // If present and valid, it takes precedence and single message vars are ignored
    $messagesJson = getenv('SYSTEM_MESSAGES');
    if ($messagesJson !== false && trim((string) $messagesJson) !== '') {
        $decoded = json_decode((string) $messagesJson, true);
        if (is_array($decoded) === true) {
            foreach ($decoded as $item) {
                $text = trim((string) ($item['text'] ?? ''));
                if ($text === '') {
                    continue;
                }

                $level = $item['level'] ?? '';
                $validLevels = ['info', 'warning', 'error'];
                $safeLevel = in_array($level, $validLevels, true) === true ? $level : 'info';

                $result[] = [
                    'body'      => strip_tags($text),
                    'url'       => trim((string) ($item['url'] ?? '')),
                    'title'     => strip_tags(trim((string) ($item['title'] ?? 'System message'))),
                    'level'     => $safeLevel,
                    'url_label' => 'Details',
                ];
            }
        }

        // If SYSTEM_MESSAGES was valid and non-empty, return it (ignore SYSTEM_MESSAGE)
        if ($result !== []) {
            return $result;
        }
    }

    // 2. Fallback to single message environment variables (only if SYSTEM_MESSAGES is empty/invalid)
    $message = getenv('SYSTEM_MESSAGE');
    if ($message !== false && trim((string) $message) !== '') {
        $levelRaw = getenv('SYSTEM_MESSAGE_LEVEL');
        $level = ($levelRaw !== false && $levelRaw !== '') ? (string) $levelRaw : 'info';
        $validLevels = ['info', 'warning', 'error'];
        $safeLevel = in_array($level, $validLevels, true) === true ? $level : 'info';

        $urlRaw = getenv('SYSTEM_MESSAGE_URL');
        $url = ($urlRaw !== false && $urlRaw !== '') ? (string) $urlRaw : '';

        $titleRaw = getenv('SYSTEM_MESSAGE_TITLE');
        $title = ($titleRaw !== false && $titleRaw !== '') ? (string) $titleRaw : 'System message';

        $result[] = [
            'body'      => strip_tags(trim((string) $message)),
            'url'       => $url,
            'title'     => strip_tags(trim($title)),
            'level'     => $safeLevel,
            'url_label' => 'Details',
        ];
    }

    return $result;
}

/**
 * Build contact links for the admin from environment variables, for use with
 * the unified notification system's $links parameter.
 *
 * Admin contacts (ADMIN_EMAIL and ADMIN_TELEGRAM) are validated at container
 * startup by /bin/load-env and stored as environment variables.
 *
 * @return array<int, array{label: string, url: string}>
 */
function get_admin_contact_links(): array
{
    $links = [];

    $adminEmailRaw = getenv('ADMIN_EMAIL');
    $email = ($adminEmailRaw === false) ? '' : trim((string) $adminEmailRaw);

    if ($email !== '') {
        $links[] = ['label' => 'Contact admin', 'url' => 'mailto:' . $email];
    }

    $adminTelegramRaw = getenv('ADMIN_TELEGRAM');
    $telegram = ($adminTelegramRaw === false) ? '' : trim((string) $adminTelegramRaw);

    if ($telegram !== '') {
        // Normalize: remove @ if present, then construct t.me URL
        $username = ltrim($telegram, '@');
        $links[] = ['label' => 'Telegram admin', 'url' => 'https://t.me/' . $username];
    }

    return $links;
}

/**
 * Render a single PHP template file with context variables.
 *
 * This function executes a template file in an isolated scope with
 * variables extracted from the $context array. The template can use
 * any variable passed in $context as a local variable.
 *
 * IMPORTANT: Never pass user input as $template or as keys in $context,
 * as this could lead to arbitrary file inclusion or variable injection.
 *
 * @param string $template Template filename or absolute path.
 * @param array<string, mixed> $context Variables to extract into template scope.
 * @return string The rendered template output.
 * @throws \Exception If template file is not found or 'template' key is used in context.
 */
function render_template(string $template, array $context = []): string
{
    if (isset($context['template']) === true) {
        throw new \Exception("Don't use `template` as a context key");
    }

    $templateFilepath = __DIR__ . '/../templates/' . $template;
    // phpcs:disable Generic.PHP.ForbiddenFunctions.Found
    extract($context);
    // phpcs:enable

    ob_start();
    try {
        if (is_file($template) === true) {
            require $template;
        } elseif (is_file($templateFilepath) === true) {
            require $templateFilepath;
        } else {
            throw new \Exception(sprintf('Unable to find template `%s`', $template));
        }
    } catch (\Throwable $e) {
        ob_end_clean();
        throw $e;
    }

    $result = ob_get_clean();
    if ($result === false) {
        return '';
    }
    return $result;
}
