<?php

declare(strict_types=1);

/**
 * HTML template rendering utilities.
 *
 * This file provides a simple two-level template rendering system
 * for generating HTML pages in RSS-Bridge. Templates are plain PHP
 * files that receive variables via explicit assignment and output HTML.
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
        $msgLevel = $message['level'] ?? null;
        $level = is_string($msgLevel) === true ? strtolower($msgLevel) : 'info';

        $msgBody = $message['body'] ?? null;
        $body = is_string($msgBody) === true ? strip_tags($msgBody) : '';

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
            if ($sm['url'] !== '') {
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
    if (is_string($appEnv) === true && $appEnv === 'DEV') {
        notify_warning('System environment: dev', 'Debug mode', 'admin');
        $cacheTypeRaw = getenv('CACHE_TYPE');
        $cacheType = is_string($cacheTypeRaw) === true ? $cacheTypeRaw : 'file';
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
    if (is_string($messagesJson) === true && trim($messagesJson) !== '') {
        $decoded = json_decode($messagesJson, true);
        if (is_array($decoded) === true) {
            foreach ($decoded as $item) {
                if (is_array($item) === false) {
                    continue;
                }

                $text = is_string($item['text'] ?? null) === true ? trim($item['text']) : '';
                if ($text === '') {
                    continue;
                }

                $level = $item['level'] ?? '';
                $validLevels = ['info', 'warning', 'error'];
                $safeLevel = in_array($level, $validLevels, true) === true ? $level : 'info';

                $url = is_string($item['url'] ?? null) === true ? trim($item['url']) : '';
                $titleRaw = $item['title'] ?? 'System message';
                $title = is_string($titleRaw) === true ? strip_tags(trim($titleRaw)) : 'System message';

                $result[] = [
                    'body'      => strip_tags($text),
                    'url'       => $url,
                    'title'     => $title,
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
    if (is_string($message) === true && trim($message) !== '') {
        $levelRaw = getenv('SYSTEM_MESSAGE_LEVEL');
        $level = is_string($levelRaw) === true && $levelRaw !== '' ? $levelRaw : 'info';
        $validLevels = ['info', 'warning', 'error'];
        $safeLevel = in_array($level, $validLevels, true) === true ? $level : 'info';

        $urlRaw = getenv('SYSTEM_MESSAGE_URL');
        $url = is_string($urlRaw) === true && $urlRaw !== '' ? $urlRaw : '';

        $titleRaw = getenv('SYSTEM_MESSAGE_TITLE');
        $title = is_string($titleRaw) === true && $titleRaw !== '' ? $titleRaw : 'System message';

        $result[] = [
            'body'      => strip_tags(trim($message)),
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
    $email = is_string($adminEmailRaw) === true ? trim($adminEmailRaw) : '';

    if ($email !== '') {
        $links[] = ['label' => 'Contact admin', 'url' => 'mailto:' . $email];
    }

    $adminTelegramRaw = getenv('ADMIN_TELEGRAM');
    $telegram = is_string($adminTelegramRaw) === true ? trim($adminTelegramRaw) : '';

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
 * variables explicitly assigned from the $context array. The template can use
 * any variable passed in $context as a local variable.
 *
 * IMPORTANT: Never pass user input as $template or as keys in $context,
 * as this could lead to arbitrary file inclusion or variable injection.
 *
 * @param string $template Template filename or absolute path.
 * @param array<string, mixed> $context Variables to assign into template scope.
 * @return string The rendered template output.
 * @throws \Exception If template file is not found or 'template' key is used in context.
 */
function render_template(string $template, array $context = []): string
{
    if (isset($context['template']) === true) {
        throw new \Exception("Don't use `template` as a context key");
    }

    $templateFilepath = __DIR__ . '/../templates/' . $template;

    // Extract context variables into local scope for template compatibility.
    // This is the only way to maintain backward compatibility with existing
    // templates that expect local variables (e.g., $bridges, $active_bridges).
    // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found
    extract($context);

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
    return is_string($result) === true ? $result : '';
}
