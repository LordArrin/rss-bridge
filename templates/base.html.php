<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="description" content="RSS-Bridge — Generate RSS feeds for websites that don't have one" />
    <meta name="theme-color" content="#E65100" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#120D0B" media="(prefers-color-scheme: dark)">
    <title>RSS-Bridge</title>
    <link href="static/style.css?<?= filemtime(__DIR__ . '/../static/style.css') ?>" rel="stylesheet">
    <link rel="icon" type="image/svg+xml" href="static/favicon.svg?<?= filemtime(__DIR__ . '/../static/favicon.svg') ?>">
    <link rel="icon" type="image/png" href="static/favicon.png?<?= filemtime(__DIR__ . '/../static/favicon.png') ?>">
    <script src="static/rss-bridge.js?<?= filemtime(__DIR__ . '/../static/rss-bridge.js') ?>" defer></script>
    <script>
        (function() {
            try {
                var favorites = JSON.parse(localStorage.getItem('rssbridge_favorites') || '[]');
                if (favorites.length > 0) {
                    document.documentElement.classList.add('has-favorites');
                }
            } catch (e) {}
        })();
    </script>
</head>

<body>
    <div class="container">
        <header>
            <a href="./" aria-label="RSS-Bridge homepage">
                <img width="400" src="static/not_boring_logo_compact.png" alt="RSS-Bridge">
            </a>
        </header>

        <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $message): ?>
                <div class="alert-<?= raw($message['level'] ?? 'info') ?>" role="alert">
                    <?= raw($message['body']) ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?= raw($page) ?>
    </div>
</body>
</html>