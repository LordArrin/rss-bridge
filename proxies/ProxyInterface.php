<?php

declare(strict_types=1);

interface ProxyInterface
{
    public function getHtml(string $url, array $options = []): string;

    public function getBinary(string $url, array $options = []): array;
    
    public function isAvailable(): bool;
    
    public function getName(): string;
}