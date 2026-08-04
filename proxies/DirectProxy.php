<?php

declare(strict_types=1);

/**
 * Default proxy - uses the standard getContents() with curl-impersonate.
 */
class DirectProxy extends ProxyAbstract
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
        
        if (isset($options['timeout'])) {
            $curlOptions[CURLOPT_TIMEOUT] = (int)($options['timeout'] / 1000);
        }

        try {
            return (string) getContents($url, $headers, $curlOptions);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Direct request failed for {$url}. " .
                "This site may require browser-based proxy (check docs). " .
                "Original error: " . $e->getMessage()
            );
        }
    }

    protected function executeRequest(string $method, string $url, array $payload, array $headers): array
    {
        throw new \RuntimeException('DirectProxy does not use executeRequest()');
    }
}