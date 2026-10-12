<?php

declare(strict_types=1);

use RSSBridge\Configuration;

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

    // Admin-defined system message from configuration (unified notifications)
    $systemMessage = Configuration::getConfig('system', 'message');
    if ($systemMessage !== null && $systemMessage !== '' && is_string($systemMessage) === true) {
        $adminLinks = get_admin_contact_links();
        $body = $systemMessage;
        // Append admin contact links as {N} placeholders resolved by the renderer.
        foreach ($adminLinks as $i => $link) {
            $body .= sprintf(' %s: {%d}', $link['label'], $i);
        }
        notify_info($body, 'System message', 'admin', [], $adminLinks);
    }

    // Debug information in dev environment (unified notifications)
    if (Configuration::getConfig('system', 'env') === 'dev') {
        notify_warning('System environment: dev', 'Debug mode', 'admin');
        notify_info(
            sprintf('Cache type: %s', (string) Configuration::getConfig('cache', 'type')),
            'Debug info',
            'admin'
        );
    }

    $context['page'] = render_template($template, $context);
    return render_template('base.html.php', $context);
}

/**
 * Build contact links for the admin from configuration, for use with the
 * unified notification system's $links parameter.
 *
 * @return array<int, array{label: string, url: string}>
 */
function get_admin_contact_links(): array
{
    $links = [];

    $email = Configuration::getConfig('admin', 'email');
    if ($email !== null && $email !== '' && is_string($email) === true) {
        $links[] = ['label' => 'Contact admin', 'url' => 'mailto:' . $email];
    }

    $telegram = Configuration::getConfig('admin', 'telegram');
    if ($telegram !== null && $telegram !== '' && is_string($telegram) === true) {
        $links[] = ['label' => 'Telegram admin', 'url' => 'https://t.me/' . ltrim($telegram, '@')];
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

    return ob_get_clean();
}
