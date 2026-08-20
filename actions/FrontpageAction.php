<?php

declare(strict_types=1);

namespace RSSBridge\Actions;

use BridgeFactory;
use BridgeMetadataCache;
use Configuration;
use Request;
use Response;
use SafeBridgeLoader;

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
        $token = $request->getAttribute('token');

        $messages = [];
        $activeBridges = 0;

        $bridgeClassNames = $this->bridgeFactory->getBridgeClassNames();

        foreach ($this->bridgeFactory->getMissingEnabledBridges() as $missingEnabledBridge) {
            $messages[] = [
                'body' => sprintf('Warning: Bridge "%s" not found', $missingEnabledBridge),
                'level' => 'warning'
            ];
        }

        $allMetadata = $this->metadataCache->getAll($this->bridgeFactory, $this->safeLoader);

        $body = '';
        foreach ($bridgeClassNames as $bridgeClassName) {
            if (!$this->bridgeFactory->isEnabled($bridgeClassName)) {
                continue;
            }

            if (!isset($allMetadata[$bridgeClassName])) {
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

        $brokenBridges = $this->safeLoader->getBrokenBridges();
        $brokenCount = count($brokenBridges);

        if ($brokenCount > 0) {
            $messages[] = [
                'body' => sprintf(
                    'Warning: %d bridge%s failed to load and %s been disabled. Check logs for details.',
                    $brokenCount,
                    $brokenCount === 1 ? '' : 's',
                    $brokenCount === 1 ? 'has' : 'have'
                ),
                'level' => 'warning'
            ];
        }

        $response = new Response(render(__DIR__ . '/../templates/frontpage.html.php', [
            'messages'          => $messages,
            'admin_email'       => Configuration::getConfig('admin', 'email'),
            'admin_telegram'    => Configuration::getConfig('admin', 'telegram'),
            'bridges'           => $body,
            'active_bridges'    => $activeBridges,
            'total_bridges'     => count($bridgeClassNames),
        ]));

        return $response;
    }

    public static function renderFromMetadata(
        array $meta,
        string $fullClassName,
        string $shortClassName,
        ?string $token
    ): string {

        $e = function($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

        if (
            Configuration::getConfig('proxy', 'url')
            && Configuration::getConfig('proxy', 'by_bridge')
        ) {
            $proxyName = Configuration::getConfig('proxy', 'name') ?: Configuration::getConfig('proxy', 'url');
            $parameters['global']['_noproxy'] = [
                'name' => sprintf('Disable proxy (%s)', $proxyName),
                'type' => 'checkbox',
            ];
        }

        if (Configuration::getConfig('cache', 'custom_timeout')) {
            $parameters['global']['_cache_timeout'] = [
                'name' => 'Cache timeout in seconds',
                'type' => 'number',
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

            <a href="#bridge-{$shortClassNameSafe}">
                <h1>#</h1>
            </a>

            <h2><a href="{$uriSafe}">{$nameSafe}</a></h2>
            <p class="description">{$descriptionSafe}</p>

            <input type="checkbox" class="showmore-box" id="showmore-{$shortClassNameSafe}" />
            <label class="showmore" for="showmore-{$shortClassNameSafe}">Show more</label>


        CARD;

        if (count($parameters) === 0) {
            $card .= self::renderForm($shortClassName, '', [], $token);
        } elseif (count($parameters) === 1 && array_key_exists('global', $parameters)) {
            $card .= self::renderForm($shortClassName, '', $parameters['global'], $token);
        } else {
            foreach ($parameters as $contextName => $contextParameters) {
                if ($contextName === 'global') {
                    continue;
                }

                if (array_key_exists('global', $parameters)) {
                    $contextParameters = array_merge($contextParameters, $parameters['global']);
                }

                $contextNameStr = is_numeric($contextName) ? (string) $contextName : $contextName;

                if (!is_numeric($contextName)) {
                    $card .= '<h5>' . $e($contextNameStr) . "</h5>\n";
                }

                $card .= self::renderForm($shortClassName, $contextNameStr, $contextParameters, $token);
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

        if (Configuration::getConfig('authentication', 'token') && $token) {
            $form .= html_input([
                    'type'  => 'hidden',
                    'name'  => 'token',
                    'value' => $token,
                ]) . "\n";
        }

        if (!empty($contextName)) {
            $form .= html_input([
                    'type'  => 'hidden',
                    'name'  => 'context',
                    'value' => $contextName,
                ]) . "\n";
        }

        $form .= '<div class="parameters">' . "\n";

        foreach ($parameters as $id => $parameter) {
            if (!isset($parameter['exampleValue'])) {
                $parameter['exampleValue'] = '';
            }

            if (!isset($parameter['defaultValue'])) {
                $parameter['defaultValue'] = '';
            }

            $idStr = is_numeric($id) ? (string) $id : $id;
            $idArg = 'arg-' . urlencode($bridgeClassName) . '-' . urlencode($contextName) . '-' . urlencode($idStr);

            $form .= html_tag('label', $parameter['name'], ['for' => $idArg]) . "\n";

            if (
                !isset($parameter['type'])
                || $parameter['type'] === 'text'
            ) {
                $form .= self::getTextInput($parameter, $idArg, $idStr) . "\n";
            } elseif ($parameter['type'] === 'number') {
                $form .= self::getNumberInput($parameter, $idArg, $idStr) . "\n";
            } elseif ($parameter['type'] === 'list') {
                $form .= self::getListInput($parameter, $idArg, $idStr) . "\n";
            } elseif ($parameter['type'] === 'checkbox') {
                $form .= self::getCheckboxInput($parameter, $idArg, $idStr) . "\n";
            } else {
                continue;
            }

            $params = [];
            if (isset($parameter['title'])) {
                $params = [
                    'title' => $parameter['title'],
                    'class' => 'info',
                ];
            }
            if ($parameter['exampleValue'] !== '') {
                $params = [
                    'title'         => sprintf("Example (right click to use):\n%s", $parameter['exampleValue']),
                    'class'         => 'info',
                    'data-for'      => $idArg,
                ];
            }

            if ($params) {
                $form .= html_tag('i', 'i', $params) . "\n";
            } else {
                $form .= html_tag('i', ' ', ['class' => 'no-info']) . "\n";
            }
        }

        $form .= "</div>\n\n";

        $form .= html_tag('button', 'Generate feed', [
                'type'          => 'submit',
                'name'          => 'format',
                'value'         => 'Html',
                'formtarget'    => '_blank',
            ]) . "\n";

        return $form . "</form>\n\n";
    }

    public static function getTextInput(array $parameter, string $id, string $name): string
    {
        $pattern = $parameter['pattern'] ?? null;
        $checked = $parameter['defaultValue'] === 'checked';
        $required = $parameter['required'] ?? false;

        return html_input([
            'id'            => $id,
            'type'          => 'text',
            'value'         => $parameter['defaultValue'],
            'placeholder'   => $parameter['exampleValue'],
            'name'          => $name,
            'pattern'       => $pattern,
            'checked'       => $checked,
            'required'      => $required,
        ]);
    }

    public static function getNumberInput(array $parameter, string $id, string $name): string
    {
        $pattern = $parameter['pattern'] ?? null;
        $checked = $parameter['defaultValue'] === 'checked';
        $required = $parameter['required'] ?? false;

        return html_input([
            'id'            => $id,
            'type'          => 'number',
            'value'         => $parameter['defaultValue'],
            'placeholder'   => $parameter['exampleValue'],
            'name'          => $name,
            'pattern'       => $pattern,
            'checked'       => $checked,
            'required'      => $required,
        ]);
    }

    public static function getCheckboxInput(array $parameter, string $id, string $name): string
    {
        return html_input([
            'id'        => $id,
            'type'      => 'checkbox',
            'name'      => $name,
            'checked'   => $parameter['defaultValue'] === 'checked',
        ]);
    }

    public static function getListInput(array $parameter, string $id, string $name): string
    {
        $list = sprintf('<select id="%s" name="%s">', htmlspecialchars($id), htmlspecialchars($name)) . "\n";

        foreach ($parameter['values'] as $name => $value) {
            if (is_array($value)) {
                $list .= '<optgroup label="' . htmlspecialchars((string) $name) . '">';
                foreach ($value as $subname => $subvalue) {
                    if (
                        $parameter['defaultValue'] === $subname
                        || $parameter['defaultValue'] === $subvalue
                    ) {
                        $list .= html_option((string) $subname, (string) $subvalue, true) . "\n";
                    } else {
                        $list .= html_option((string) $subname, (string) $subvalue) . "\n";
                    }
                }
                $list .= '</optgroup>';
            } else {
                if (
                    $parameter['defaultValue'] === $name
                    || $parameter['defaultValue'] === $value
                ) {
                    $list .= html_option((string) $name, (string) $value, true) . "\n";
                } else {
                    $list .= html_option((string) $name, (string) $value) . "\n";
                }
            }
        }

        $list .= "</select>\n";

        return $list;
    }
}
