<?php

declare(strict_types=1);

class Rule34Bridge extends GelbooruBase
{
    const NAME = 'Rule34';
    const URI = 'https://api.rule34.xxx/';
    const VIEW_URI = 'https://rule34.xxx/';
    const DESCRIPTION = 'Returns images from rule34.xxx search';
    const MAINTAINER = 'LordArrin';
    const CACHE_TIMEOUT = 1800;

    const CONFIGURATION = [
        'api_key' => [
            'required' => false,
        ],
        'user_id' => [
            'required' => false,
        ],
    ];

    const PARAMETERS = [
        'global' => [
            'api_key' => [
                'name' => 'API Key',
                'type' => 'text',
                'required' => false,
                'title' => 'Your Rule34 API key. Leave empty to use server default'
            ],
            'user_id' => [
                'name' => 'User ID',
                'type' => 'number',
                'required' => false,
                'title' => 'Your Rule34 user ID. Leave empty to use server default'
            ],
            'q' => [
                'name' => 'Query (Tags)',
                'type' => 'text',
                'required' => true,
                'title' => 'Tags for search, separated by commas or spaces'
            ],
            'l' => [
                'name' => 'Posts limit',
                'type' => 'number',
                'required' => false,
                'title' => 'Maximum number of posts to fetch (API hard limit is 1000)',
                'defaultValue' => 10
            ],
            'exclude_ai' => [
                'name' => 'Exclude AI-generated content',
                'type' => 'checkbox',
                'required' => false,
                'defaultValue' => 'checked'
            ],
            'hide_details' => [
                'name' => 'Hide tags and source',
                'type' => 'checkbox',
                'required' => false,
                'defaultValue' => 'checked'
            ]
        ],
        0 => []
    ];

    private string $apiKey = '';

    private string $userId = '';

    public function collectData(): void
    {
        $apiKey = (string) ($this->getInput('api_key') ?: $this->getOption('api_key') ?: '');
        $userId = (string) ($this->getInput('user_id') ?: $this->getOption('user_id') ?: '');

        if ($apiKey === '' || $userId === '') {
            throw new \Exception('API key and user ID are required. Provide them in the bridge parameters or in config.ini.php under the [Rule34Bridge] section.');
        }

        $this->apiKey = $apiKey;
        $this->userId = $userId;

        parent::collectData();
    }

    public function getName(): string
    {
        $query = $this->normalizeQuery((string) ($this->getInput('q') ?? ''));

        return $query !== '' ? $query : parent::getName();
    }

    protected function getFullURI(): string
    {
        $query = $this->normalizeQuery((string) ($this->getInput('q') ?? ''));

        if ($this->getInput('exclude_ai') === true) {
            $query = trim($query . ' -ai_generated');
        }

        $params = [
            'page' => 'dapi',
            's' => 'post',
            'q' => 'index',
            'json' => 1,
            'pid' => 0,
            'limit' => (int) ($this->getInput('l') ?? 10),
            'tags' => $query,
            'api_key' => $this->apiKey ?? '',
            'user_id' => $this->userId ?? '',
        ];

        return $this->getURI() . 'index.php?' . http_build_query($params);
    }

    protected function extractPosts(mixed $data): array|\stdClass
    {
        if ($data instanceof \stdClass && ($data->success ?? null) === false) {
            throw new \Exception('API error: ' . ($data->message ?? 'Unknown error'));
        }

        return $data instanceof \stdClass ? ($data->post ?? []) : $data;
    }

    protected function getItemFromElement(\stdClass $element): array
    {
        $pageUrl = self::VIEW_URI . 'index.php?page=post&s=view&id=' . (int) ($element->id ?? 0);
        $thumbnailUrl = (string) ($element->preview_url ?? $this->buildThumbnailURI($element));

        $content = sprintf(
            '<a href="%s"><img src="%s" /></a><br><br><b>Dimensions:</b> %d x %d',
            htmlspecialchars($pageUrl, ENT_QUOTES),
            htmlspecialchars($thumbnailUrl, ENT_QUOTES),
            (int) ($element->width ?? 0),
            (int) ($element->height ?? 0)
        );

        if ($this->getInput('hide_details') === false) {
            $content .= sprintf(
                '<br><br><b>Tags:</b> %s',
                htmlspecialchars((string) ($element->tags ?? ''), ENT_QUOTES)
            );

            $source = (string) ($element->source ?? '');
            if ($source !== '') {
                $content .= sprintf(
                    '<br><br><b>Source:</b> <a href="%1$s">%1$s</a>',
                    htmlspecialchars($source, ENT_QUOTES)
                );
            }
        }

        return [
            'uri' => $pageUrl,
            'id' => $pageUrl,
            'title' => sprintf('Image %d', (int) ($element->id ?? 0)),
            'content' => $content,
            'author' => (string) ($element->owner ?? 'unknown'),
            'timestamp' => $this->getTimestamp($element),
        ];
    }
}
