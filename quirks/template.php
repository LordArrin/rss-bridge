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
 * - Adds system messages from configuration
 * - Adds debug information when in dev mode
 * - Renders the content template
 * - Wraps it in base.html.php layout
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

    $context['messages'] = $context['messages'] ?? [];

    // Add system message from configuration if present
    $systemMessage = Configuration::getConfig('system', 'message');
    if ($systemMessage !== null && $systemMessage !== '' && is_string($systemMessage) === true) {
        $context['messages'][] = [
            'body' => $systemMessage,
            'level' => 'info',
        ];
    }

    // Add debug information in dev environment
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
