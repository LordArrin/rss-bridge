<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="description" content="RSS-Bridge — <?= e($title) ?>" />
    <meta name="theme-color" content="#E65100" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#120D0B" media="(prefers-color-scheme: dark)">
    <title><?= e($title) ?> - RSS-Bridge</title>
    <link href="static/style.css?<?= filemtime(__DIR__ . '/../static/style.css') ?>" rel="stylesheet">
    <link rel="icon" type="image/png" href="static/favicon.png">
    <link rel="icon" type="image/svg+xml" href="static/favicon.svg">

    <?php foreach ($formats as $format): ?>
        <link
            href="<?= e($format['url']) ?>"
            title="<?= e($format['name']) ?>"
            rel="alternate"
            type="<?= e($format['type']) ?>"
        >
    <?php endforeach; ?>

    <meta name="robots" content="noindex, follow">
</head>

<body>
    <div class="container">
        <header>
            <a href="./" aria-label="RSS-Bridge homepage">
                <img width="400" src="static/not_boring_logo_compact.png" alt="RSS-Bridge">
            </a>
        </header>

        <h1 class="pagetitle">
            <a href="<?= e($uri) ?>" target="_blank" rel="noopener noreferrer"><?= e($title) ?></a>
        </h1>

        <div class="buttons">
            <a href="./#bridge-<?= e($bridge_name) ?>" class="button backbutton">
                < back to rss-bridge
            </a>

            <?php foreach ($formats as $format): ?>
                <a href="<?= e($format['url']) ?>" class="button rss-feed">
                    <?= e($format['name']) ?>
                </a>
            <?php endforeach; ?>

            <?php if ($donation_uri): ?>
                <a href="<?= e($donation_uri) ?>" class="button rss-feed" rel="noopener noreferrer" target="_blank">
                    Donate to maintainer
                </a>
            <?php endif; ?>
        </div>

        <?php foreach ($items as $item): ?>
            <section class="feeditem">
                <h2>
                    <a class="itemtitle" href="<?= e($item['url']) ?>" target="_blank" rel="noopener noreferrer">
                        <?= e(strip_tags($item['title'])) ?>
                    </a>
                </h2>

                <div class="item-meta">
                    <?php if ($item['timestamp']): ?>
                        <p>
                            <time datetime="<?= date('c', $item['timestamp']) ?>">
                                <?= date('Y-m-d H:i:s', $item['timestamp']) ?>
                            </time>
                        </p>
                    <?php endif; ?>

                    <?php if (!empty($item['author'])): ?>
                        <p class="author">by: <?= e($item['author']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="item-content">
                    <!-- Intentionally not escaping for html context -->
                    <?= break_annoying_html_tags($item['content']) ?>
                </div>

                <?php if (!empty($item['enclosures'])): ?>
                    <div class="item-attachments">
                        <p>Attachments:</p>
                        <ul>
                            <?php foreach ($item['enclosures'] as $enclosure): ?>
                                <li class="enclosure">
                                    <a href="<?= e($enclosure) ?>" rel="noopener noreferrer nofollow" target="_blank">
                                        <?= e(substr($enclosure, strrpos($enclosure, '/') + 1)) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!empty($item['categories'])): ?>
                    <div class="item-categories">
                        <p>Categories:</p>
                        <ul>
                            <?php foreach ($item['categories'] as $category): ?>
                                <li class="category"><?= e($category) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
</body>
</html>