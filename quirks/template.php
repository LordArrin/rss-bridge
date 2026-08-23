<?php

/**
 * Render template using base.html.php as base
 */
function render(string $template, array $context = []): string
{
    if ($template === 'base.html.php') {
        throw new \Exception('Do not render base.html.php into itself');
    }
    $context['messages'] = $context['messages'] ?? [];
    if (Configuration::getConfig('system', 'message')) {
        $context['messages'][] = [
            'body' => Configuration::getConfig('system', 'message'),
            'level' => 'info',
        ];
    }
    if (Configuration::getConfig('system', 'env') === 'dev') {
        $context['messages'][] = [
            'body' => 'System environment: dev',
            'level' => 'error'
        ];
        $context['messages'][] = [
            'body' => sprintf('Cache type: %s', Configuration::getConfig('cache', 'type')),
            'level' => 'info'
        ];
    }
    $context['page'] = render_template($template, $context);
    return render_template('base.html.php', $context);
}

/**
 * Render php template with context
 *
 * DO NOT PASS USER INPUT IN $template OR $context (keys!)
 */
function render_template(string $template, array $context = []): string
{
    if (isset($context['template'])) {
        throw new \Exception("Don't use `template` as a context key");
    }
    $templateFilepath = __DIR__ . '/../templates/' . $template;
    extract($context);
    ob_start();
    try {
        if (is_file($template)) {
            require $template;
        } elseif (is_file($templateFilepath)) {
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