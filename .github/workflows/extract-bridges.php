<?php
$bridgesDir = __DIR__ . '/../../bridges-v2';
$bridgeFiles = [];

$files = glob($bridgesDir . '/*.php');
sort($files);

foreach ($files as $file) {
    $basename = basename($file, '.php');
    
    // Skip Base classes (GelbooruBase, NginxBase, PhilomenaBase, etc.)
    if (!str_ends_with($basename, 'Bridge')) {
        continue;
    }
    
    $content = file_get_contents($file);
    
    $name = '';
    $uri = '';
    $description = '';
    $maintainer = '';
    
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
    
    $bridgeFiles[] = [
        'file' => $basename,
        'name' => $name,
        'uri' => $uri,
        'description' => $description,
        'maintainer' => $maintainer
    ];
}

echo json_encode([
    'active' => count($bridgeFiles),
    'bridges' => $bridgeFiles
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);