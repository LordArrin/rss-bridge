<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class AlpinePackagesBridge extends BridgeAbstract
{
    public const NAME = 'Alpine Packages';
    public const URI = 'https://pkgs.alpinelinux.org';
    public const DESCRIPTION = 'Get Alpine package versions';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        '' => [
            'package' => [
                'type' => 'text',
                'name' => 'Package Name',
                'required' => true,
                'exampleValue' => 'curl',
                'title' => 'Name of the package. Use * and ? as wildcards. For example: curl-dev, curl-* or curl-???.'
            ],
            'branch' => [
                'type' => 'text',
                'name' => 'Package branch',
                'required' => true,
                'exampleValue' => 'v3.24',
                'title' => 'Name of the branch. For example: edge, v3.24, v3.23, etc.'
            ],
            'repository' => [
                'type' => 'list',
                'name' => 'Repository name',
                'values' => [
                    'All' => 'all',
                    'Community' => 'community',
                    'Main' => 'main',
                    'Testing' => 'testing'
                ],
                'defaultValue' => 'all'
            ],
            'architecture' => [
                'type' => 'list',
                'name' => 'Architecture',
                'values' => [
                    'All' => 'all',
                    'aarch64' => 'aarch64',
                    'armhf' => 'armhf',
                    'armv7' => 'armv7',
                    'loongarch64' => 'loongarch64',
                    'ppc64le' => 'ppc64le',
                    'riscv64' => 'riscv64',
                    's390x' => 's390x',
                    'x86' => 'x86',
                    'x86_64' => 'x86_64'
                ],
                'defaultValue' => 'x86_64'
            ]
        ]
    ];

    public function getURI(): string
    {
        $package = $this->getInput('package');
        $branch = $this->getInput('branch');
        $repository = $this->getInput('repository');
        $architecture = $this->getInput('architecture');

        $isPackageString = is_string($package);
        $isPackageNotEmpty = ($package !== '');
        $isBranchString = is_string($branch);
        $isBranchNotEmpty = ($branch !== '');

        if ($isPackageString === true && $isPackageNotEmpty === true && $isBranchString === true && $isBranchNotEmpty === true) {
            $packageEncoded = urlencode(strtolower(trim($package)));
            $branchClean = strtolower(trim($branch));

            $isRepoString = is_string($repository);
            $repositoryStr = ($isRepoString === true) ? strtolower($repository) : 'all';
            $repoParam = ($repositoryStr === 'all') ? '' : $repositoryStr;

            $isArchString = is_string($architecture);
            $architectureStr = ($isArchString === true) ? strtolower(trim($architecture)) : 'all';
            $archParam = ($architectureStr === 'all') ? '' : $architectureStr;

            return self::URI . '/packages?name=' . $packageEncoded . '&branch=' . $branchClean . '&repo=' . $repoParam . '&arch=' . $archParam . '&origin=&flagged=&maintainer=';
        }

        return parent::getURI();
    }

    public function getName(): string
    {
        $packageName = $this->getInput('package');
        $branchName = $this->getInput('branch');
        $repositoryName = $this->getInput('repository');
        $architecture = $this->getInput('architecture');

        $isPackageString = is_string($packageName);
        $isPackageNotEmpty = ($packageName !== '');

        if ($isPackageString === true && $isPackageNotEmpty === true) {
            $packageNameStr = strtolower($packageName);
            $name = $packageNameStr . ' (';

            $parts = [];

            $isBranchString = is_string($branchName);
            $isBranchNotEmpty = ($branchName !== '');
            if ($isBranchString === true && $isBranchNotEmpty === true) {
                $parts[] = 'branch ' . strtolower($branchName);
            }

            $isRepoString = is_string($repositoryName);
            $isRepoNotEmpty = ($repositoryName !== '');
            if ($isRepoString === true && $isRepoNotEmpty === true) {
                $repositoryNameStr = strtolower($repositoryName);
                $isNotAll = ($repositoryNameStr !== 'all');
                if ($isNotAll === true) {
                    $parts[] = 'repo ' . $repositoryNameStr;
                }
            }

            $isArchString = is_string($architecture);
            $isArchNotEmpty = ($architecture !== '');
            if ($isArchString === true && $isArchNotEmpty === true) {
                $architectureStr = strtolower($architecture);
                $isNotAll = ($architectureStr !== 'all');
                if ($isNotAll === true) {
                    $parts[] = 'arch ' . $architectureStr;
                }
            }

            $name .= implode(', ', $parts);
            $name .= ') - Alpine packages';
            return $name;
        }

        return parent::getName();
    }

    public function collectData(): void
    {
        $dom = getSimpleHTMLDOM($this->getURI());
        $table = $dom->querySelector('table.pure-table.pure-table-striped');

        $isTableNull = ($table === null);
        if ($isTableNull === true) {
            throwServerException('Table not found. The site layout may have changed.');
        }

        $tbody = $table->querySelector('tbody');
        $isTbodyNull = ($tbody === null);
        if ($isTbodyNull === true) {
            throwServerException('Table body not found. The site layout may have changed.');
        }

        $rows = $tbody->querySelectorAll('tr');

        foreach ($rows as $tr) {
            $itemData = $this->getElementData($tr);

            $isPackageEmpty = empty($itemData['package']);
            $isVersionEmpty = empty($itemData['version']);
            if ($isPackageEmpty === true || $isVersionEmpty === true) {
                continue;
            }

            $time = strtotime($itemData['bdate']);
            $timestamp = ($time === false) ? null : $time;

            $this->items[] = [
                'title' => $itemData['package'] . '-' . $itemData['version'],
                'uri' => $itemData['package_href'],
                'timestamp' => $timestamp,
                'uid' => $itemData['package'] . $itemData['version'] . $itemData['arch'] . $itemData['branch'] . $itemData['repo'],
                'author' => $itemData['maintainer'],
                'categories' => [
                    'arch: ' . $itemData['arch'],
                    'branch: ' . $itemData['branch'],
                    'repo: ' . $itemData['repo']
                ],
                'content' => $this->generateContent($itemData)
            ];
        }

        $isItemsEmpty = empty($this->items);
        if ($isItemsEmpty === true) {
            throwServerException('No packages found. Check your input parameters or the site status.');
        }
    }

    private function generateContent(array $data): string
    {
        $content = '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;">';
        $keys = ['package', 'version', 'branch', 'repo', 'arch', 'maintainer', 'bdate'];
        $labels = [
            'package' => 'Package',
            'version' => 'Version',
            'branch' => 'Branch',
            'repo' => 'Repository',
            'arch' => 'Architecture',
            'maintainer' => 'Maintainer',
            'bdate' => 'Build Date',
        ];

        foreach ($keys as $key) {
            $isKeySet = isset($data[$key]);
            if ($isKeySet === false) {
                continue;
            }

            $value = $data[$key];
            $content .= '<tr><th style="text-align: left; padding-right: 10px;">' . e($labels[$key]) . '</th><td>';

            $hrefKey = $key . '_href';
            $isHrefSet = isset($data[$hrefKey]);
            $isHrefNotEmpty = ($isHrefSet === true && $data[$hrefKey] !== '');

            if ($isHrefNotEmpty === true) {
                $content .= '<a href="' . e($data[$hrefKey]) . '">' . e($value) . '</a>';
            } else {
                $content .= e($value);
            }
            $content .= '</td></tr>';
        }

        $content .= '</table>';
        return $content;
    }

    private function getElementData(\Dom\Element $tr): array
    {
        $classesWithLink = ['package', 'repo', 'arch', 'maintainer'];
        $classesWithoutLink = ['branch', 'bdate'];

        $data = [];

        foreach ($classesWithLink as $class) {
            $td = $tr->querySelector('td.' . $class);
            $a = $td?->querySelector('a');
            $data[$class] = trim($a?->textContent ?? '');
            $href = $a?->getAttribute('href');
            $isHrefString = is_string($href);
            $data[$class . '_href'] = ($isHrefString === true) ? urljoin(self::URI, $href) : '';
        }

        foreach ($classesWithoutLink as $class) {
            $td = $tr->querySelector('td.' . $class);
            $data[$class] = trim($td?->textContent ?? '');
        }

        $tdVersion = $tr->querySelector('td.version');
        $strong = $tdVersion?->querySelector('strong[class*="hint--right"]');
        $isStrongNull = ($strong === null);
        if ($isStrongNull === true) {
            $strong = $tdVersion?->querySelector('strong');
        }

        $strongText = $strong?->textContent;
        $tdVersionText = $tdVersion?->textContent;
        $versionText = $strongText ?? $tdVersionText ?? '';
        $data['version'] = trim($versionText);

        return $data;
    }
}
