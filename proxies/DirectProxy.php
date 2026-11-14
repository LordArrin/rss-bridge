<?php

declare(strict_types=1);

namespace RSSBridge\Proxies;

use RSSBridge\Http\Response;

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
        $headers = $this->normalizeHeaders($options['headers'] ?? []);
        $curlOptions = [];

        if (isset($options['timeout']) === true) {
            $curlOptions[CURLOPT_TIMEOUT] = (int) (self::toInt($options['timeout'], 0) / 1000);
        }

        try {
            return getContents($url, $headers, $curlOptions);
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
            $curlOptions[CURLOPT_TIMEOUT] = (int) (self::toInt($options['timeout'], 0) / 1000);
        }

        try {
            $response = getContents($url, [], $curlOptions, true);

            $body = $response->getBody();
            $headers = $response->getHeaders();
            $contentType = $headers['content-type'][0] ?? 'application/octet-stream';
            $type = trim(explode(';', $contentType)[0]);

            if ($body === '') {
                throw new \RuntimeException("Empty response for {$url}");
            }

            return ['body' => $body, 'type' => $type];
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Direct binary fetch failed for {$url}: {$e->getMessage()}"
            );
        }
    }

    /**
     * Normalizes mixed header input into a list of header lines accepted by getContents().
     *
     * @return list<string>
     */
    private function normalizeHeaders(mixed $rawHeaders): array
    {
        if (is_array($rawHeaders) === false) {
            return [];
        }

        $headers = [];
        foreach ($rawHeaders as $key => $value) {
            if (is_string($value) === false) {
                continue;
            }

            if (is_string($key) === true) {
                $headers[] = $key . ': ' . $value;
            } else {
                $headers[] = $value;
            }
        }

        return $headers;
    }
}
