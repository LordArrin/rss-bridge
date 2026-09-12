<?php
$bridgesDir = __DIR__ . '/../../bridges-v2';
$allFiles = [];
$bridgeFiles = [];

$files = glob($bridgesDir . '/*.php');
sort($files);

foreach ($files as $file) {
    $basename = basename($file, '.php');
    $content = file_get_contents($file);
    $isBridge = str_ends_with($basename, 'Bridge');
    
    $name = '';
    $uri = '';
    $description = '';
    $maintainer = '';
    $configuration = [];
    
    if (preg_match("/public\s+const\s+NAME\s*=\s*['\"]([^'\"]+)['\"];?/", $content, $matches)) {
        $name = $matches[1];
    }
    if (preg_match("/public\s+const\s+URI\s*=\s*['\"]([^'\"]+)['\"];?/", $content, $matches)) {
        $uri = $matches[1];
    }
    if (preg_match("/public\s+const\s+DESCRIPTION\s*=\s*['\"]([^'\"]+)['\"];?/", $content, $matches)) {
        $description = $matches[1];
    }
    if (preg_match("/public\s+const\s+MAINTAINER\s*=\s*['\"]([^'\"]+)['\"];?/", $content, $matches)) {
        $maintainer = $matches[1];
    }
    
    // Extract CONFIGURATION keys (required options)
    if (preg_match("/public\s+const\s+CONFIGURATION\s*=\s*\[(.*?)\];/s", $content, $matches)) {
        $configBlock = $matches[1];
        // Find keys with 'required' => true
        if (preg_match_all("/['\"]([^'\"]+)['\"]\s*=>\s*\[\s*['\"]required['\"]\s*=>\s*true/s", $configBlock, $keyMatches)) {
            $configuration = $keyMatches[1];
        }
    }
    
    $entry = [
        'file' => $basename,
        'name' => $name,
        'uri' => $uri,
        'description' => $description,
        'maintainer' => $maintainer,
        'is_bridge' => $isBridge,
        'configuration' => $configuration
    ];
    
    $allFiles[] = $entry;
    if ($isBridge) {
        $bridgeFiles[] = $entry;
    }
}

echo json_encode([
    'total' => count($allFiles),
    'active' => count($bridgeFiles),
    'bridges' => $bridgeFiles
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
