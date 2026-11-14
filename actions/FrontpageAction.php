<?php

declare(strict_types=1);

namespace RSSBridge\Actions;

use RSSBridge\BridgeFactory;
use RSSBridge\BridgeMetadataCache;
use RSSBridge\SafeBridgeLoader;
use RSSBridge\Http\Request;
use RSSBridge\Http\Response;

final class FrontpageAction implements ActionInterface
{
    private BridgeFactory $bridgeFactory;
    private SafeBridgeLoader $safeLoader;
    private BridgeMetadataCache $metadataCache;

    public function __construct(
        BridgeFactory $bridgeFactory,
        SafeBridgeLoader $safeLoader,
        BridgeMetadataCache $metadataCache
    ) {
        $this->bridgeFactory = $bridgeFactory;
        $this->safeLoader = $safeLoader;
        $this->metadataCache = $metadataCache;
    }

    public function __invoke(Request $request): Response
    {
        $tokenRaw = $request->getAttribute('token');
        $token = is_string($tokenRaw) === true ? $tokenRaw : null;

        $activeBridges = 0;

        $bridgeClassNames = $this->bridgeFactory->getBridgeClassNames();

        // Notifications from BridgeFactory / SafeBridgeLoader (unified system v2)
        foreach ($this->bridgeFactory->getMissingEnabledBridges() as $missingEnabledBridge) {
            notify_warning(
                sprintf('Bridge "%s" is listed in ENABLED_BRIDGES but was not found and cannot be used.', $missingEnabledBridge),
                'Bridge not found',
                'BridgeFactory',
                [],
                [['label' => $missingEnabledBridge, 'url' => './?action=list']]
            );
        }

        $allMetadata = $this->metadataCache->getAll($this->bridgeFactory, $this->safeLoader);

        $body = '';
        foreach ($bridgeClassNames as $bridgeClassName) {
            if ($this->bridgeFactory->isEnabled($bridgeClassName) === false) {
                continue;
            }

            if (isset($allMetadata[$bridgeClassName]) === false) {
                continue;
            }

            $meta = $allMetadata[$bridgeClassName];
            $body .= self::renderFromMetadata(
                $meta,
                $bridgeClassName,
                $this->bridgeFactory->getShortClassName($bridgeClassName),
                $token
            );
            $activeBridges++;
        }

        // Report every bridge that SafeBridgeLoader had to disable (unified system v2)
        foreach ($this->safeLoader->getBrokenBridges() as $brokenName => $brokenInfo) {
            notify_error(
                sprintf(
                    'Bridge "%s" failed to load and has been disabled. Reason: %s',
                    $brokenName,
                    $brokenInfo['message']
                ),
                'Broken bridge',
                'SafeBridgeLoader',
                [],
                [[
                    'label' => $this->bridgeFactory->getShortClassName($brokenName),
                    'url'   => './?action=list',
                ]]
            );
        }

        $response = new Response(render(__DIR__ . '/../templates/frontpage.html.php', [
            'bridges'        => $body,
            'active_bridges' => $activeBridges,
            'total_bridges'  => count($bridgeClassNames),
        ]));

        return $response;
    }

    /**
     * @param array{name: string, uri: string, description: string, parameters: array<int|string, array<string, mixed>>, domain: string, short_name: string, maintainer: string, cache_timeout: int} $meta
     */
    public static function renderFromMetadata(
        array $meta,
        string $fullClassName,
        string $shortClassName,
        ?string $token
    ): string {
        $e = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $uri = $meta['uri'];
        $name = $meta['name'];
        $description = $meta['description'];
        $parameters = $meta['parameters'];
        $domain = $meta['domain'];
        $shortName = $meta['short_name'];

        $shortClassNameSafe = $e($shortClassName);
        $nameSafe = $e($name);
        $shortNameSafe = $e($shortName);
        $domainSafe = $e($domain);
        $uriSafe = $e($uri);
        $descriptionSafe = $e($description);

        // Read custom timeout setting directly from environment
        if (filter_var(getenv('CACHE_CUSTOM_TIMEOUT'), FILTER_VALIDATE_BOOLEAN) === true) {
            $parameters['global']['_cache_timeout'] = [
                'name'         => 'Cache timeout in seconds',
                'type'         => 'number',
                'defaultValue' => $meta['cache_timeout']
            ];
        }

        $card = <<<CARD
            <section
                class="bridge-card"
                id="bridge-{$shortClassNameSafe}"
                data-ref="{$nameSafe}"
                data-short-name="{$shortNameSafe}"
                data-domain="{$domainSafe}"
            >

            <button
                type="button"
                class="favorite-btn"
                data-bridge="{$shortClassNameSafe}"
                aria-label="Add to favorites"
                title="Add to favorites"
            >
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/>
                </svg>
            </button>

            <a href="#bridge-{$shortClassNameSafe}" class="hash-link" aria-label="Toggle bridge card">
                <h1>#</h1>
            </a>

            <h2><a href="{$uriSafe}">{$nameSafe}</a></h2>
            <p class="description">{$descriptionSafe}</p>

            <input type="checkbox" class="showmore-box" id="showmore-{$shortClassNameSafe}" />
            <label class="showmore" for="showmore-{$shortClassNameSafe}">Show more</label>

        CARD;

        if (count($parameters) === 0) {
            $card .= self::renderForm($shortClassName, '', [], $token);
        } elseif (count($parameters) === 1 && array_key_exists('global', $parameters) === true) {
            $card .= self::renderForm($shortClassName, '', $parameters['global'], $token);
        } else {
            foreach ($parameters as $contextName => $contextParameters) {
                if ($contextName === 'global') {
                    continue;
                }

                if (array_key_exists('global', $parameters) === true) {
                    $contextParameters = array_merge($contextParameters, $parameters['global']);
                }

                if (is_numeric($contextName) === false) {
                    $card .= '<h5>' . $e($contextName) . "</h5>\n";
                }

                // Cast is now valid because PHPDoc says $contextName can be int|string
                $card .= self::renderForm($shortClassName, (string) $contextName, $contextParameters, $token);
            }
        }

        $card .= html_tag('label', 'Show less', [
                'class' => 'showless',
                'for'   => "showmore-$shortClassNameSafe",
            ]) . "\n";

        $card .= html_tag('p', $meta['maintainer'], ['class' => 'maintainer']) . "\n";
        $card .= "</section>\n\n";

        return $card;
    }

    /**
     * @param array<int|string, mixed> $parameters
     */
    private static function renderForm(
        string $bridgeClassName,
        string $contextName,
        array $parameters,
        ?string $token
    ): string {
        $form = <<<EOD
        <form method="GET" action="?" class="bridge-form">
            <input type="hidden" name="action" value="display" />
            <input type="hidden" name="bridge" value="{$bridgeClassName}" />

        EOD;

        // Read authentication setting directly from environment
        if (filter_var(getenv('AUTHENTICATION_ENABLE'), FILTER_VALIDATE_BOOLEAN) === true && $token !== null && $token !== '') {
            $form .= html_input([
                'type'  => 'hidden',
                'name'  => 'token',
                'value' => $token,
            ]) . "\n";
        }

        if ($contextName !== '') {
            $form .= html_input([
                'type'  => 'hidden',
                'name'  => 'context',
                'value' => $contextName,
            ]) . "\n";
        }

        $form .= '<div class="parameters">' . "\n";

        foreach ($parameters as $id => $parameter) {
            if (is_array($parameter) === false) {
                continue;
            }

            $exampleValueRaw = $parameter['exampleValue'] ?? '';
            $exampleValue = is_string($exampleValueRaw) === true ? $exampleValueRaw : (is_scalar($exampleValueRaw) === true ? (string) $exampleValueRaw : '');
            $parameter['exampleValue'] = $exampleValue;

            if (isset($parameter['defaultValue']) === false) {
                $parameter['defaultValue'] = '';
            }

            $nameRaw = $parameter['name'] ?? '';
            $parameterName = is_string($nameRaw) === true ? $nameRaw : (is_scalar($nameRaw) === true ? (string) $nameRaw : '');

            // Cast is now valid because PHPDoc says $id can be int|string
            $idStr = (string) $id;
            $idArg = 'arg-' . urlencode($bridgeClassName) . '-' . urlencode($contextName) . '-' . urlencode($idStr);

            $form .= html_tag('label', $parameterName, ['for' => $idArg]) . "\n";

            $type = $parameter['type'] ?? 'text';

            if ($type === 'text') {
                $form .= self::getTextInput($parameter, $idArg, $idStr) . "\n";
            } elseif ($type === 'number') {
                $form .= self::getNumberInput($parameter, $idArg, $idStr) . "\n";
            } elseif ($type === 'list') {
                $form .= self::getListInput($parameter, $idArg, $idStr) . "\n";
            } elseif ($type === 'checkbox') {
                $form .= self::getCheckboxInput($parameter, $idArg, $idStr) . "\n";
            } else {
                continue;
            }

            // Build the tooltip text for the info button. Keep it in a
            // data-attribute: native title tooltips are unavailable on
            // touch devices and the JS TooltipManager renders a proper,
            // viewport-clamped tooltip from this text instead.
            $tooltipLines = [];

            $titleRaw = $parameter['title'] ?? null;
            if (is_string($titleRaw) === true) {
                $tooltipLines[] = $titleRaw;
            } elseif (is_scalar($titleRaw) === true) {
                $tooltipLines[] = (string) $titleRaw;
            }

            if ($exampleValue !== '') {
                $tooltipLines[] = sprintf(
                    "Example (long press / right click to use):\n%s",
                    $exampleValue
                );
            }

            if ($tooltipLines !== []) {
                $tooltipText = implode("\n\n", $tooltipLines);
                $params = [
                    'class'      => 'info',
                    'title'      => $tooltipText,
                    'tabindex'   => '0',
                    'role'       => 'button',
                    'aria-label' => 'More information about ' . $parameterName,
                    'data-for'   => $idArg,
                ];
                $form .= html_tag('i', 'i', $params) . "\n";
            } else {
                $form .= html_tag('i', ' ', ['class' => 'no-info']) . "\n";
            }
        }

        $form .= "</div>\n\n";

        $form .= html_tag('button', 'Generate feed', [
            'type'       => 'submit',
            'name'       => 'format',
            'value'      => 'Html',
            'formtarget' => '_blank',
        ]) . "\n";

        return $form . "</form>\n\n";
    }

    /**
     * @param array<mixed, mixed> $parameter
     */
    public static function getTextInput(array $parameter, string $id, string $name): string
    {
        $pattern = $parameter['pattern'] ?? null;
        $checked = $parameter['defaultValue'] === 'checked';
        $required = $parameter['required'] ?? false;

        return html_input([
            'id'          => $id,
            'type'        => 'text',
            'value'       => $parameter['defaultValue'],
            'placeholder' => $parameter['exampleValue'],
            'name'        => $name,
            'pattern'     => $pattern,
            'checked'     => $checked,
            'required'    => $required,
        ]);
    }

    /**
     * @param array<mixed, mixed> $parameter
     */
    public static function getNumberInput(array $parameter, string $id, string $name): string
    {
        $pattern = $parameter['pattern'] ?? null;
        $checked = $parameter['defaultValue'] === 'checked';
        $required = $parameter['required'] ?? false;

        return html_input([
            'id'          => $id,
            'type'        => 'number',
            'value'       => $parameter['defaultValue'],
            'placeholder' => $parameter['exampleValue'],
            'name'        => $name,
            'pattern'     => $pattern,
            'checked'     => $checked,
            'required'    => $required,
        ]);
    }

    /**
     * @param array<mixed, mixed> $parameter
     */
    public static function getCheckboxInput(array $parameter, string $id, string $name): string
    {
        return html_input([
            'id'      => $id,
            'type'    => 'checkbox',
            'name'    => $name,
            'checked' => $parameter['defaultValue'] === 'checked',
        ]);
    }

    /**
     * @param array<mixed, mixed> $parameter
     */
    public static function getListInput(array $parameter, string $id, string $name): string
    {
        $list = sprintf('<select id="%s" name="%s">', htmlspecialchars($id), htmlspecialchars($name)) . "\n";

        $values = $parameter['values'] ?? [];
        if (is_array($values) === false) {
            $values = [];
        }

        $defaultValue = $parameter['defaultValue'] ?? null;

        foreach ($values as $key => $value) {
            $keyStr = (string) $key;

            if (is_array($value) === true) {
                $list .= '<optgroup label="' . htmlspecialchars($keyStr) . '">';

                foreach ($value as $subkey => $subvalue) {
                    $subkeyStr = (string) $subkey;
                    $subvalueStr = is_string($subvalue) === true ? $subvalue : (is_scalar($subvalue) === true ? (string) $subvalue : '');

                    if (
                        $defaultValue === $subkey
                        || $defaultValue === $subvalue
                    ) {
                        $list .= html_option($subkeyStr, $subvalueStr, true) . "\n";
                    } else {
                        $list .= html_option($subkeyStr, $subvalueStr) . "\n";
                    }
                }

                $list .= '</optgroup>';
            } else {
                $valueStr = is_string($value) === true ? $value : (is_scalar($value) === true ? (string) $value : '');

                if (
                    $defaultValue === $key
                    || $defaultValue === $value
                ) {
                    $list .= html_option($keyStr, $valueStr, true) . "\n";
                } else {
                    $list .= html_option($keyStr, $valueStr) . "\n";
                }
            }
        }

        $list .= "</select>\n";

        return $list;
    }
}
