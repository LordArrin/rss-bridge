<?php
// Extract bridge metadata from PHP files

$bridgesDir = __DIR__ . '/../../bridges-v2';
$output = [];

$files = glob($bridgesDir . '/*.php');
sort($files);

foreach ($files as $file) {
    $content = file_get_contents($file);
    $basename = basename($file, '.php');
    
    // Extract constants using regex
    $name = '';
    $uri = '';
    $description = '';
    $maintainer = '';
    
    // NAME
    if (preg_match("/public\s+const\s+NAME\s*=\s*['\"]([^'\"]+)['\"];?/", $content, $matches)) {
        $name = $matches[1];
    }
    
    // URI
    if (preg_match("/public\s+const\s+URI\s*=\s*['\"]([^'\"]+)['\"];?/", $content, $matches)) {
        $uri = $matches[1];
    }
    
    // DESCRIPTION
    if (preg_match("/public\s+const\s+DESCRIPTION\s*=\s*['\"]([^'\"]+)['\"];?/", $content, $matches)) {
        $description = $matches[1];
    }
    
    // MAINTAINER
    if (preg_match("/public\s+const\s+MAINTAINER\s*=\s*['\"]([^'\"]+)['\"];?/", $content, $matches)) {
        $maintainer = $matches[1];
    }
    
    $output[] = [
        'file' => $basename,
        'name' => $name,
        'uri' => $uri,
        'description' => $description,
        'maintainer' => $maintainer
    ];
}

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);