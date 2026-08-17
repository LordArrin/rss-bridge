<section class="searchbar">
    <h3>Search</h3>
    <input
        type="text"
        name="searchfield"
        id="searchfield"
        placeholder="Insert URL or bridge name"
        value=""
        autocomplete="off"
    >
</section>

<?= raw($bridges) ?>

<section class="footer">
    <div class="footer-badges">
        <?php
        $version = Configuration::getVersion();
        $encodedVersion = rawurlencode($version);
        $dockerUrl = "https://hub.docker.com/repository/docker/lordarrin/rss-bridge/tags/" . $encodedVersion;
        $badgeText = 'lordarrin/rss-bridge:' . $version;
        $adminEmail = $admin_email ?? '';
        $adminTelegram = $admin_telegram ?? '';
        ?>

        <!-- Docker Badge -->
        <a href="<?= e($dockerUrl) ?>" rel="noopener noreferrer" target="_blank" class="badge">
            <span class="badge__left">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M13 4h2v2h-2V4zm-3 0h2v2h-2V4zm-3 0h2v2H7V4zm-3 2h2v2H4V6zm3 0h2v2h-2V6zm3 0h2v2h-2V6zm3 0h2v2h-2V6zm3 0h2v2h-2V6zm-9 2h2v2H7V8zm3 0h2v2h-2V8zm3 0h2v2h-2V8zm-3 2h2v2h-2v-2zm3 0h2v2h-2v-2zm3.13 1.75c-.26-.12-.57-.2-.93-.2-.58 0-1.06.23-1.35.61-.17.23-.26.5-.28.79h-.02c-.1-.2-.29-.37-.54-.47-.22-.09-.5-.14-.81-.14-1.14 0-2.02.88-2.02 2.02 0 .05 0 .09.01.14H5c-.83 0-1.5.67-1.5 1.5S4.17 16.5 5 16.5h14c.83 0 1.5-.67 1.5-1.5s-.67-1.5-1.5-1.5h-1.23c.03-.11.05-.22.05-.34 0-.82-.6-1.41-1.19-1.41z"/>
                </svg>docker
            </span>
            <span class="badge__right">
                <?= e($badgeText) ?>
            </span>
        </a>

        <!-- GitHub Badge -->
        <a href="https://github.com/LordArrin/rss-bridge" rel="noopener noreferrer" target="_blank" class="badge">
            <span class="badge__left">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/>
                </svg>github
            </span>
            <span class="badge__right">
                LordArrin/rss-bridge
            </span>
        </a>

        <!-- License Badge -->
        <a href="https://github.com/LordArrin/rss-bridge/blob/master/LICENSE" rel="noopener noreferrer" target="_blank" class="badge">
            <span class="badge__left">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/>
                </svg>license
            </span>
            <span class="badge__right">
                AGPL-3.0
            </span>
        </a>

        <?php if (!empty($adminEmail)): ?>
        <!-- Email Badge -->
        <a href="mailto:<?= e($adminEmail) ?>" class="badge">
            <span class="badge__left">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                </svg>email
            </span>
            <span class="badge__right">
                <?= e($adminEmail) ?>
            </span>
        </a>
        <?php endif; ?>

        <?php if (!empty($adminTelegram)): ?>
        <!-- Telegram Badge -->
        <a href="<?= e($adminTelegram) ?>" rel="noopener noreferrer" target="_blank" class="badge">
            <span class="badge__left">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/>
                </svg>telegram
            </span>
            <span class="badge__right">
                <?= e($adminTelegram) ?>
            </span>
        </a>
        <?php endif; ?>

        <!-- Active bridges counter -->
        <span class="footer-counter">
            <?= $active_bridges ?>/<?= $total_bridges ?> active bridges
        </span>
    </div>
</section>