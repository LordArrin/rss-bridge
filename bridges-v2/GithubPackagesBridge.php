<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\GithubClient;

final class GithubPackagesBridge extends BridgeAbstract
{
    public const NAME = 'GitHub Packages';
    public const URI = 'https://github.com/';
    public const DESCRIPTION = 'List GitHub packages or versions for organization, user or project';
    public const MAINTAINER = 'No maintainer';

    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        [
            'organization' => [
                'type' => 'text',
                'name' => 'GitHub organization/user',
                'exampleValue' => 'RSS-Bridge',
                'required' => true,
                'title' => 'Name of the organization/user: https://github.com/<org>/...',
            ],
            'owner_type' => [
                'type' => 'list',
                'name' => 'Owner type',
                'values' => [
                    'Organization' => 'org',
                    'User' => 'user',
                ],
                'defaultValue' => 'org',
                'title' => 'REST API uses different endpoints for organizations and users',
            ],
            'repository' => [
                'type' => 'text',
                'name' => 'GitHub repository',
                'exampleValue' => 'rss-bridge',
                'required' => false,
                'title' => 'Name of the repository: https://github.com/<org>/<repository>',
            ],
            'packagename' => [
                'type' => 'text',
                'name' => 'Package name',
                'exampleValue' => 'rss-bridge',
                'required' => false,
                'title' => 'Name of the package. Example "rss-bridge" or "curl-container/curl".',
            ],
            'packagetype' => [
                'type' => 'list',
                'name' => 'Package Type',
                'values' => [
                    'All' => 'all',
                    'Container' => 'container',
                    'Maven' => 'maven',
                    'npm' => 'npm',
                    'NuGet' => 'nuget',
                    'RubyGems' => 'rubygems',
                ],
                'defaultValue' => 'container',
                'title' => 'Type of package. Do not select "All" if a package name is provided.',
            ],
        ],
    ];

    public function collectData(): void
    {
        $org = (string) $this->getInput('organization');
        $repo = (string) $this->getInput('repository');
        $name = (string) $this->getInput('packagename');
        $type = (string) $this->getInput('packagetype');
        $isOrg = (string) $this->getInput('owner_type') !== 'user';

        if ($org === '') {
            throwClientException('Provide at least an organization.');
        }
        if ($name !== '' && $repo === '') {
            throwClientException('Provide a repository when providing a package name or do not provide the package name.');
        }
        if ($name !== '' && $type === 'all') {
            throwClientException('Do not provide package type as "all" when specifying a package name.');
        }

        $client = $this->client();

        if ($name !== '') {
            foreach ($client->fetchPackageVersions($org, $type, $name, $isOrg) as $version) {
                $item = $this->buildVersionItem($version);
                if ($item !== null) {
                    $this->items[] = $item;
                }
            }
            return;
        }

        foreach ($client->fetchPackages($org, $type, $isOrg) as $package) {
            $item = $this->buildPackageItem($package, $org, $repo);
            if ($item !== null) {
                $this->items[] = $item;
            }
        }
    }

    public function getName(): string
    {
        $org = (string) $this->getInput('organization');
        if ($org === '') {
            return parent::getName();
        }

        $repo = (string) $this->getInput('repository');
        $name = (string) $this->getInput('packagename');
        $type = (string) $this->getInput('packagetype');

        if ($repo !== '') {
            if ($name !== '') {
                return $org . '/' . $repo . ' - ' . $name . ' - GitHub ' . $type . ' Package versions';
            }
            if ($type === 'all') {
                return $org . '/' . $repo . ' - GitHub Packages';
            }
            return $org . '/' . $repo . ' - GitHub ' . $type . ' Packages';
        }

        if ($type === 'all') {
            return $org . ' - GitHub Packages';
        }
        return $org . ' - GitHub ' . $type . ' Packages';
    }

    public function getURI(): string
    {
        $org = (string) $this->getInput('organization');
        if ($org === '') {
            return parent::getURI();
        }

        $repo = (string) $this->getInput('repository');
        $name = (string) $this->getInput('packagename');
        $type = (string) $this->getInput('packagetype');
        $scope = (string) $this->getInput('owner_type') === 'user' ? 'users' : 'orgs';
        $orgEnc = rawurlencode($org);

        if ($repo !== '' && $name !== '' && $type !== 'all') {
            return self::URI . $orgEnc . '/' . rawurlencode($repo) . '/pkgs/' . $type . '/' . rawurlencode($name) . '/versions';
        }

        $base = self::URI . $scope . '/' . $orgEnc . '/packages';
        $query = [];
        if ($repo !== '') {
            $query['repo_name'] = $repo;
        }
        if ($type !== 'all') {
            $query['ecosystem'] = $type;
        }
        if ($query !== []) {
            return $base . '?' . http_build_query($query);
        }
        return $base;
    }

    private function client(): GithubClient
    {
        $cache = $this->cache;
        $logger = $this->logger;
        if ($cache === null || $logger === null) {
            throwServerException('GitHub bridge requires cache and logger services.');
        }
        return new GithubClient($cache, $logger);
    }

    /**
     * @param array<string, mixed> $version
     * @return array<string, mixed>|null
     */
    private function buildVersionItem(array $version): ?array
    {
        $uri = (string) ($version['html_url'] ?? '');
        if ($uri === '') {
            return null;
        }

        $title = (string) ($version['name'] ?? '');
        if ($title === '') {
            $title = (string) ($version['id'] ?? $uri);
        }

        $item = [
            'title' => $title,
            'uri' => $uri,
            'uid' => $uri,
        ];

        $ts = strtotime((string) ($version['created_at'] ?? ''));
        if ($ts !== false) {
            $item['timestamp'] = $ts;
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $package
     * @return array<string, mixed>|null
     */
    private function buildPackageItem(array $package, string $org, string $repo): ?array
    {
        if ($repo !== '') {
            $repository = $package['repository'] ?? null;
            $fullName = is_array($repository) === true ? ($repository['full_name'] ?? '') : '';
            if (is_string($fullName) === true && $fullName !== '' && $fullName !== $org . '/' . $repo) {
                return null;
            }
        }

        $uri = (string) ($package['html_url'] ?? '');
        if ($uri === '') {
            return null;
        }

        $item = [
            'title' => (string) ($package['name'] ?? $uri),
            'uri' => $uri,
            'uid' => $uri,
        ];

        $ts = strtotime((string) ($package['updated_at'] ?? $package['created_at'] ?? ''));
        if ($ts !== false) {
            $item['timestamp'] = $ts;
        }

        return $item;
    }
}
