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

    // Admin-defined system messages from configuration (unified notifications).
    // Legacy admin notification mechanism: [system] message / message_url and
    // the per-bridge custom_message / custom_title options used to be rendered
    // manually as alert-* markup in base.html.php. They are now converted
    // transparently into unified notifications queued here and rendered once
    // by render_notifications() in the layout.
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
 * Collect the legacy admin-defined messages from configuration and normalize
 * them into plain-data descriptors ready for the unified notification system.
 *
 * Supported configuration keys (all optional):
 *   [system] message        = "Text"           -> info notification body
 *   [system] message_url    = "https://..."    -> link appended to the body
 *   [system] message_title  = "Heading"        -> notification title
 *   [system] message_level  = "info|warning|error" (default: info)
 *   [system] messages[]     = { text, url, title, level }  (array form, via
 *                             ini sections, e.g. [messages.text], see README)
 *
 * Text fields are treated as PLAIN TEXT (the notification renderer escapes
 * them); any HTML coming from configuration is stripped here so that admins
 * cannot inject markup through the old alert-* mechanism.
 *
 * @return array<int, array{body: string, url: string, title: string, level: string, url_label: string}>
 */
function get_admin_system_messages(): array
{
    /** @var array<int, array<string, mixed>> $raw */
    $raw = [];

    // Single-message form (backwards compatible with upstream rss-bridge).
    $message = Configuration::getConfig('system', 'message');
    if (is_string($message) === true && trim($message) !== '') {
        $raw[] = [
            'text'  => $message,
            'url'   => (string) (Configuration::getConfig('system', 'message_url') ?? ''),
            'title' => (string) (Configuration::getConfig('system', 'message_title') ?? 'System message'),
            'level' => (string) (Configuration::getConfig('system', 'message_level') ?? 'info'),
        ];
    }

    // Multi-message form: [system] messages[] = ... or [messages.N] sections.
    $messages = Configuration::getConfig('system', 'messages');
    if (is_array($messages) === true) {
        foreach ($messages as $key => $entry) {
            if (is_string($entry) === true && trim($entry) !== '') {
                // messages[] = "plain text"
                $raw[] = [
                    'text'  => $entry,
                    'url'   => '',
                    'title' => 'System message',
                    'level' => 'info',
                ];
            } elseif (is_array($entry) === true) {
                // messages[] = { text = "...", url = "...", title = "...", level = "warning" }
                $text = trim((string) ($entry['text'] ?? ''));
                if ($text === '') {
                    continue;
                }

                $title = trim((string) ($entry['title'] ?? ''));
                $level = trim((string) ($entry['level'] ?? ''));

                $raw[] = [
                    'text'  => $text,
                    'url'   => trim((string) ($entry['url'] ?? '')),
                    'title' => $title === '' ? 'System message' : $title,
                    'level' => $level === '' ? 'info' : $level,
                ];
            } elseif (is_int($key) === false) {
                // Skipped: unexpected scalar under an integer key.
            }
        }
    }

    $levels = ['info', 'warning', 'error'];
    $result = [];
    foreach ($raw as $item) {
        $level = strtolower((string) $item['level']);
        if (in_array($level, $levels, true) === false) {
            $level = 'info';
        }
        $result[] = [
            // Plain text only: strip any HTML left over from the old manual markup.
            'body'      => strip_tags(trim((string) $item['text'])),
            'url'       => (string) $item['url'],
            'title'     => strip_tags(trim((string) $item['title'])),
            'level'     => $level,
            'url_label' => 'Details',
        ];
    }

    return $result;
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
