<?php

declare(strict_types=1);

namespace RSSBridge\Proxies;

/**
 * Default proxy - uses the standard getContents() with curl-impersonate.
 */
final class DirectProxy extends ProxyAbstract
{
    public function getName(): string
    {
        return 'Direct (curl-impersonate)';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    protected function fetchHtml(string $url, array $options): string
    {
        $headers = $options['headers'] ?? [];
        $curlOptions = [];

        if (isset($options['timeout']) === true) {
            $curlOptions[CURLOPT_TIMEOUT] = (int)($options['timeout'] / 1000);
        }

        try {
            return (string) getContents($url, $headers, $curlOptions);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Direct request failed for {$url}. This site may require browser-based proxy (check docs). Original error: {$e->getMessage()}"
            );
        }
    }

    protected function executeRequest(string $method, string $url, array $payload, array $headers): array
    {
        throw new \RuntimeException('DirectProxy does not use executeRequest()');
    }

    /**
     * Fetches binary content via direct HTTP.
     *
     * @return array{body: string, type: string}
     */
    public function getBinary(string $url, array $options = []): array
    {
        $curlOptions = [];

        if (isset($options['timeout']) === true) {
            $curlOptions[CURLOPT_TIMEOUT] = (int)($options['timeout'] / 1000);
        }

        try {
            /** @var \Response $response */
            $response = getContents($url, [], $curlOptions, true);

            $body = $response->getBody();
            $headers = $response->getHeaders();
            $contentType = $headers['content-type'][0] ?? 'application/octet-stream';
            $type = trim(explode(';', $contentType)[0]);

            if ($body === '' || $body === null) {
                throw new \RuntimeException("Empty response for {$url}");
            }

            return ['body' => $body, 'type' => $type];
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Direct binary fetch failed for {$url}: {$e->getMessage()}"
            );
        }
    }
}
