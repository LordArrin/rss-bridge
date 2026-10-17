<?php

/**
 * Unified notification system.
 *
 * A standalone, self-contained API for producing styled notifications in the
 * web interface. This is now the single notification mechanism used by the
 * templates: the legacy "messages" array (alert-info/warning/error markup in
 * base.html.php) has been removed; any code that still passes a 'messages'
 * key to render() is transparently converted to these notifications.
 *
 * Types:
 *   - info    -> blue
 *   - warning -> yellow
 *   - error   -> red
 *
 * Each notification supports: title, body text (with hyperlinks), author, and
 * a list of buttons (rendered as links or forms).
 *
 * Usage from other code:
 *   1) Collect notifications during request handling (argument order:
 *      body, title, author, buttons, links):
 *        notify_info('Feed updated', 'Status', null, [], [
 *            ['label' => 'docs', 'url' => 'https://example.org'],
 *        ]);
 *        notify_warning('Slow down', 'Rate limit', 'system', [
 *            ['label' => 'Retry', 'url' => './'],
 *        ]);
 *        notify_error('HTTP 500', 'Bridge failed', 'GithubBridge');
 *
 *   2) Render them in a template:
 *        <?= render_notifications() ?>
 *      or get the raw array first:
 *        $items = get_notifications();
 *        echo render_notification($items[0]);
 *
 * Loading mechanism: registered in composer.json "files" autoload, so the
 * functions are available globally without any explicit require.
 */

declare(strict_types=1);

// Internal storage for pending notifications.
$GLOBALS['__notifications'] = $GLOBALS['__notifications'] ?? [];

/**
 * Return the list of valid notification types mapped to their CSS modifiers.
 *
 * @return array<string, string>
 */
function notification_types(): array
{
    return [
        'info'    => 'notification-info',
        'warning' => 'notification-warning',
        'error'   => 'notification-error',
    ];
}

/**
 * Create a normalized notification array.
 *
 * Text fields (title/body/author) are treated as PLAIN TEXT and will be HTML
 * escaped at render time. Hyperlinks inside the body must be passed via the
 * $links parameter so they can be rendered safely (href is validated and
 * escaped by the renderer). Buttons support both plain links and POST forms.
 *
 * @param string $type    One of: info, warning, error.
 * @param string $body    Notification text (plain text; may contain {N} placeholders
 *                        referencing entries of $links, e.g. "Read {0}").
 * @param string|null $title Optional heading.
 * @param string|null $author Optional author label.
 * @param array<int, array{label: string, url?: string, method?: string, attributes?: array<string,string>}> $buttons
 *                        Optional buttons. Each button: label + url (GET link)
 *                        or label + url + method='post' (renders a small form).
 * @param array<int, array{label: string, url: string}> $links
 *                        Optional hyperlinks substituted into {N} placeholders in body.
 * @return array<string, mixed>
 */
function build_notification(
    string $type,
    string $body,
    ?string $title = null,
    ?string $author = null,
    array $buttons = [],
    array $links = []
): array {
    $type = strtolower($type);
    if (array_key_exists($type, notification_types()) === false) {
        // Unknown types degrade gracefully to "info" instead of throwing.
        $type = 'info';
    }

    return [
        'type'    => $type,
        'title'   => $title,
        'body'    => $body,
        'author'  => $author,
        'buttons' => $buttons,
        'links'   => $links,
    ];
}

/**
 * Queue a notification for later rendering.
 *
 * @param array<string, mixed> $notification Result of build_notification().
 */
function add_notification(array $notification): void
{
    $GLOBALS['__notifications'][] = $notification;
}

/** Convenience wrappers ---------------------------------------------------- */

function notify_info(string $body, ?string $title = null, ?string $author = null, array $buttons = [], array $links = []): void
{
    add_notification(build_notification('info', $body, $title, $author, $buttons, $links));
}

function notify_warning(string $body, ?string $title = null, ?string $author = null, array $buttons = [], array $links = []): void
{
    add_notification(build_notification('warning', $body, $title, $author, $buttons, $links));
}

function notify_error(string $body, ?string $title = null, ?string $author = null, array $buttons = [], array $links = []): void
{
    add_notification(build_notification('error', $body, $title, $author, $buttons, $links));
}

/** Accessors --------------------------------------------------------------- */

/**
 * @return array<int, array<string, mixed>> All queued notifications.
 */
function get_notifications(): array
{
    /** @var array<int, array<string, mixed>> $list */
    $list = $GLOBALS['__notifications'];
    return $list;
}

/**
 * Remove all queued notifications (e.g. after rendering or in tests).
 */
function clear_notifications(): void
{
    $GLOBALS['__notifications'] = [];
}

/** Rendering --------------------------------------------------------------- */

/**
 * Validate a URL for use in href/action attributes. Only http(s), mailto and
 * relative URLs are allowed; anything else (javascript:, data:) is rejected.
 */
function notification_safe_url(string $url): ?string
{
    $trimmed = trim($url);
    if ($trimmed === '') {
        return null;
    }
    // Relative URL (no scheme) — allowed.
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $trimmed) === 0) {
        return $trimmed;
    }
    if (preg_match('~^(https?|mailto):~i', $trimmed) === 1) {
        return $trimmed;
    }
    return null;
}

/**
 * Substitute {N} placeholders in the plain-text body with safe anchor tags
 * built from the $links array. The surrounding text stays escaped.
 *
 * @param string $body Plain-text body.
 * @param array<int, array{label: string, url: string}> $links
 * @return string HTML (already escaped internally).
 */
function notification_body_html(string $body, array $links): string
{
    // htmlspecialchars does not escape curly braces, so placeholders survive escaping.
    $escaped = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return preg_replace_callback('/\{(\d+)\}/', function (array $m) use ($links): string {
            $index = (int) $m[1];
            if (isset($links[$index]) === false || is_array($links[$index]) === false) {
                return $m[0]; // leave unknown placeholder as-is
            }
            $link = $links[$index];
            $url = notification_safe_url((string) ($link['url'] ?? ''));
            $label = htmlspecialchars((string) ($link['label'] ?? $url ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($url === null) {
                return $label; // unsafe URL: render label as plain text
            }
            $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer nofollow">%s</a>',
                $safeUrl,
                $label
            );
        }, $escaped) ?? $escaped;
}

/**
 * Render one notification as an HTML snippet.
 *
 * @param array<string, mixed> $n Notification produced by build_notification().
 */
function render_notification(array $n): string
{
    $types = notification_types();
    $type = (string) ($n['type'] ?? 'info');
    $class = $types[$type] ?? $types['info'];

    $html = sprintf('<div class="notification %s" role="alert">', e($class));

    $html .= '<div class="notification-icon" aria-hidden="true">';
    $html .= match ($type) {
        'error'   => '&#10006;',   // ✖
        'warning' => '&#9888;',    // ⚠
        default   => '&#8505;',    // ℹ
    };
    $html .= '</div>';

    $html .= '<div class="notification-content">';

    if (($n['title'] ?? '') !== '') {
        $html .= sprintf('<strong class="notification-title">%s</strong>', e((string) $n['title']));
    }

    $html .= sprintf(
        '<span class="notification-body">%s</span>',
        notification_body_html((string) ($n['body'] ?? ''), (array) ($n['links'] ?? []))
    );

    if (($n['author'] ?? '') !== '') {
        $html .= sprintf('<span class="notification-author">%s</span>', e((string) $n['author']));
    }

    $html .= '</div>'; // .notification-content

    $buttons = (array) ($n['buttons'] ?? []);
    if ($buttons === []) {
        // No buttons to render.
    } else {
        $html .= '<div class="notification-actions">';
        foreach ($buttons as $button) {
            if (is_array($button) === false) {
                continue;
            }
            $label = htmlspecialchars((string) ($button['label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $url = notification_safe_url((string) ($button['url'] ?? ''));
            if ($url === null) {
                continue; // skip buttons with missing/unsafe URL entirely
            }
            $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $method = strtolower((string) ($button['method'] ?? 'get'));

            if ($method === 'post') {
                $hidden = '';
                foreach ((array) ($button['attributes'] ?? []) as $key => $value) {
                    $hidden .= sprintf(
                        '<input type="hidden" name="%s" value="%s">',
                        htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    );
                }
                $html .= sprintf(
                    '<form method="post" action="%s" class="notification-form">%s<button type="submit" class="notification-button">%s</button></form>',
                    $safeUrl,
                    $hidden,
                    $label
                );
            } else {
                $html .= sprintf(
                    '<a href="%s" class="notification-button"%s>%s</a>',
                    $safeUrl,
                    str_starts_with($safeUrl, 'http') === true ? ' target="_blank" rel="noopener noreferrer"' : '',
                    $label
                );
            }
        }
        $html .= '</div>'; // .notification-actions
    }

    $html .= '</div>'; // .notification

    return $html;
}

/**
 * Render all queued notifications and (optionally) flush them.
 *
 * Returns an empty string when there is nothing to render, so it is safe to
 * embed directly into templates: <?= render_notifications() ?>
 */
function render_notifications(bool $clear = true): string
{
    $items = get_notifications();
    if ($items === []) {
        return '';
    }
    if ($clear === true) {
        clear_notifications();
    }

    $html = '<div class="notifications">';
    foreach ($items as $item) {
        $html .= render_notification($item);
    }
    $html .= '</div>';

    return $html;
}
