<?php

/**
 * Video embedding utilities.
 *
 * Single source of truth for video players: trusted hosts, watch-URL to
 * player-URL conversion, iframe markup and the legacy YouTube renderer.
 * The HTML sanitizer only asks is_allowed_video_embed() and knows nothing
 * about individual providers.
 *
 * Loading mechanism: registered in composer.json "files" autoload.
 */

declare(strict_types=1);

const VIDEO_EMBED_PROVIDERS = [
    'youtube' => ['youtube.com', 'youtube-nocookie.com', 'youtu.be'],
    'vk' => ['vk.com', 'vk.ru', 'vkvideo.ru'],
    'rutube' => ['rutube.ru'],
    'vimeo' => ['vimeo.com'],
    'dzen' => ['dzen.ru'],
    'ok' => ['ok.ru'],
    'coub' => ['coub.com'],
    'twitch' => ['twitch.tv'],
];

const VIDEO_EMBED_ALLOW = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen; screen-wake-lock';

/**
 * @return list<string>
 */
function video_embed_hosts(): array
{
    $hosts = [];
    foreach (VIDEO_EMBED_PROVIDERS as $providerHosts) {
        foreach ($providerHosts as $host) {
            $hosts[] = $host;
        }
    }

    return $hosts;
}

function video_embed_host(string $url): string
{
    $parts = parse_url(trim($url));
    if (is_array($parts) === false || isset($parts['host']) === false) {
        return '';
    }

    $scheme = strtolower($parts['scheme'] ?? '');
    if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
        return '';
    }

    return strtolower($parts['host']);
}

function video_embed_provider(string $url): ?string
{
    $host = video_embed_host($url);
    if ($host === '') {
        return null;
    }

    foreach (VIDEO_EMBED_PROVIDERS as $provider => $domains) {
        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain) === true) {
                return $provider;
            }
        }
    }

    return null;
}

/**
 * Checks an <iframe> attribute string against the trusted video hosts.
 *
 * "srcdoc" is rejected outright: it overrides "src" and would execute
 * attacker HTML in our origin.
 */
function is_allowed_video_embed(string $attributes): bool
{
    if (preg_match('#\bsrcdoc\s*=#i', $attributes) === 1) {
        return false;
    }

    if (preg_match('#\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $attributes, $m) !== 1) {
        return false;
    }

    $src = '';
    foreach ([1, 2, 3] as $group) {
        $candidate = $m[$group] ?? '';
        if ($candidate !== '') {
            $src = $candidate;
            break;
        }
    }

    $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($src === '') {
        return false;
    }

    return video_embed_provider($src) !== null;
}

/**
 * Converts a watch URL into a player URL, or null when not convertible.
 *
 * Supported options: nocookie (bool, YouTube), parent (string, Twitch).
 *
 * @param array<string, mixed> $options
 */
function video_embed_url(string $url, array $options = []): ?string
{
    $url = trim($url);
    $provider = video_embed_provider($url);
    if ($provider === null) {
        return null;
    }

    return match ($provider) {
        'youtube' => video_embed_youtube_url($url, $options),
        'vk' => video_embed_vk_url($url),
        'rutube' => video_embed_rutube_url($url),
        'vimeo' => video_embed_vimeo_url($url),
        'dzen' => video_embed_dzen_url($url),
        'ok' => video_embed_ok_url($url),
        'coub' => video_embed_coub_url($url),
        'twitch' => video_embed_twitch_url($url, $options),
        default => null,
    };
}

/**
 * Builds ready-to-use iframe markup, or '' when the URL is not supported.
 *
 * Supported options: width, height, title, lazy, nocookie, parent.
 *
 * @param array<string, mixed> $options
 */
function video_embed_iframe(string $url, array $options = []): string
{
    $embedUrl = video_embed_url($url, $options);
    if ($embedUrl === null) {
        return '';
    }

    $width = isset($options['width']) === true && is_numeric($options['width']) === true ? (int) $options['width'] : 640;
    $height = isset($options['height']) === true && is_numeric($options['height']) === true ? (int) $options['height'] : 360;
    $title = isset($options['title']) === true && is_string($options['title']) === true ? $options['title'] : 'Video player';
    $lazy = isset($options['lazy']) === false || $options['lazy'] !== false;

    $width = max(1, min(3840, $width));
    $height = max(1, min(2160, $height));

    return sprintf(
        '<iframe src="%s" width="%d" height="%d" title="%s" loading="%s" frameborder="0" allow="%s" allowfullscreen></iframe>',
        e($embedUrl),
        $width,
        $height,
        e($title),
        $lazy === true ? 'lazy' : 'eager',
        VIDEO_EMBED_ALLOW
    );
}

function video_embed_youtube_id(string $string): ?string
{
    $regex = '#(?:https?://|//)?(?:www\.|m\.|.+\.)?(?:youtu\.be/|youtube(?:-nocookie|)\.com/(?:embed/|v/|shorts/|live/|feeds/api/videos/|watch\?v=|watch\?.+&v=))([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])#i';
    if (preg_match($regex, $string, $m) === 1) {
        return $m[1];
    }

    if (preg_match_all('#(?<![A-Za-z0-9_-])([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])#', $string, $all) === false) {
        return null;
    }

    foreach ($all[1] as $candidate) {
        $hasDigit = preg_match('#[0-9]#', $candidate) === 1;
        $hasLower = preg_match('#[a-z]#', $candidate) === 1;
        $hasUpper = preg_match('#[A-Z]#', $candidate) === 1;
        if (($hasDigit === true && ($hasLower === true || $hasUpper === true)) || ($hasLower === true && $hasUpper === true)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $options
 */
function video_embed_youtube_url(string $url, array $options = []): ?string
{
    $id = video_embed_youtube_id($url);
    if ($id === null) {
        return null;
    }

    $nocookie = isset($options['nocookie']) === true && $options['nocookie'] === true;
    $host = $nocookie === true ? 'www.youtube-nocookie.com' : 'www.youtube.com';

    return 'https://' . $host . '/embed/' . $id;
}

function video_embed_vk_url(string $url): ?string
{
    if (str_contains($url, 'video_ext.php') === true) {
        return $url;
    }

    if (preg_match('#video(-?\d+)_(\d+)(?:/([0-9a-zA-Z]+))?#', $url, $m) !== 1) {
        return null;
    }

    $embed = 'https://vk.com/video_ext.php?oid=' . $m[1] . '&id=' . $m[2];
    $hash = $m[3] ?? '';

    return $hash !== '' ? $embed . '&hash=' . $hash : $embed;
}

function video_embed_rutube_url(string $url): ?string
{
    if (preg_match('#rutube\.ru/(?:play/embed|video|shorts)/([0-9a-zA-Z]+)#i', $url, $m) === 1) {
        return 'https://rutube.ru/play/embed/' . $m[1];
    }

    return null;
}

function video_embed_vimeo_url(string $url): ?string
{
    if (preg_match('#vimeo\.com/(?:.*?/)?(\d{6,})(?:/([0-9a-zA-Z]+))?#i', $url, $m) !== 1) {
        return null;
    }

    $embed = 'https://player.vimeo.com/video/' . $m[1];
    $hash = $m[2] ?? '';
    if ($hash === '' && preg_match('#[?&]h=([0-9a-zA-Z]+)#', $url, $q) === 1) {
        $hash = $q[1];
    }

    return $hash !== '' ? $embed . '?h=' . $hash : $embed;
}

function video_embed_dzen_url(string $url): ?string
{
    if (preg_match('#dzen\.ru/(?:video/watch|embed)/([0-9a-zA-Z]+)#i', $url, $m) === 1) {
        return 'https://dzen.ru/embed/' . $m[1];
    }

    return null;
}

function video_embed_ok_url(string $url): ?string
{
    if (preg_match('#ok\.ru/(?:video|videoembed|live)/(\d+)#i', $url, $m) === 1) {
        return 'https://ok.ru/videoembed/' . $m[1];
    }

    return null;
}

function video_embed_coub_url(string $url): ?string
{
    if (preg_match('#coub\.com/(?:view|embed)/([0-9a-zA-Z]+)#i', $url, $m) === 1) {
        return 'https://coub.com/embed/' . $m[1];
    }

    return null;
}

/**
 * Twitch requires a "parent" parameter matching the embedding site.
 *
 * @param array<string, mixed> $options
 */
function video_embed_twitch_url(string $url, array $options = []): ?string
{
    $parent = isset($options['parent']) === true && is_string($options['parent']) === true ? $options['parent'] : '';
    if ($parent === '') {
        $domain = getenv('DOMAIN');
        $parent = is_string($domain) === true && $domain !== '' ? video_embed_host($domain) : '';
    }

    if ($parent === '') {
        return null;
    }

    $encoded = rawurlencode($parent);

    if (preg_match('#twitch\.tv/videos/(\d+)#i', $url, $m) === 1) {
        return 'https://player.twitch.tv/?video=v' . $m[1] . '&parent=' . $encoded . '&autoplay=false';
    }

    if (preg_match('#(?:clips\.twitch\.tv/|/clip/)([0-9A-Za-z_-]+)#', $url, $m) === 1) {
        return 'https://clips.twitch.tv/embed?clip=' . $m[1] . '&parent=' . $encoded;
    }

    if (preg_match('~twitch\.tv/([0-9A-Za-z_]+)(?:[/?#]|$)~', $url, $m) === 1) {
        return 'https://player.twitch.tv/?channel=' . $m[1] . '&parent=' . $encoded . '&autoplay=false';
    }

    return null;
}

/**
 * Legacy handleYoutube() behaviour: iframe or clickable thumbnail.
 */
function video_embed_youtube(string $string, bool $useIframe = false, bool $useNocookie = false): string
{
    $videoID = video_embed_youtube_id($string);
    if ($videoID === null) {
        return '';
    }

    if ($useIframe === true) {
        $embedUri = $useNocookie === true ? 'https://www.youtube-nocookie.com/embed/' . $videoID : 'https://www.youtube.com/embed/' . $videoID;

        return sprintf(<<<EOD
<iframe width="560" height="315" src="%s" title="YouTube video player" frameborder="0"
allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
referrerpolicy="strict-origin" allowfullscreen></iframe>
EOD
         , $embedUri);
    }

    return video_embed_youtube_thumbnail($videoID);
}

function video_embed_youtube_thumbnail(string $videoID): string
{
    $videoUri = 'https://www.youtube.com/watch?v=' . $videoID;

    $thumbnailJpegBaseUri = 'https://i.ytimg.com/vi/' . $videoID;
    $jpegSrcset = sprintf(
        '%1$s/mqdefault.jpg 320w, %1$s/0.jpg 480w, %1$s/hqdefault.jpg 481w, %1$s/sddefault.jpg 640w, %1$s/hq720.jpg 720w, %1$s/maxresdefault.jpg 721w',
        $thumbnailJpegBaseUri
    );

    $thumbnailWebpBaseUri = 'https://i.ytimg.com/vi_webp/' . $videoID;
    $webpSrcset = sprintf(
        '%1$s/mqdefault.webp 320w, %1$s/0.webp 480w, %1$s/hqdefault.webp 481w, %1$s/sddefault.webp 640w, %1$s/hq720.webp 720w, %1$s/maxresdefault.webp 721w',
        $thumbnailWebpBaseUri
    );

    $fallbackUri = $thumbnailJpegBaseUri . '/maxresdefault.jpg';

    return sprintf(<<<EOD
<a href="%s">
    <picture>
        <source srcset="%s" type="image/webp" referrerpolicy="no-referrer" />
        <img srcset="%s" src="%s" alt="Video thumbnail" title="YouTube video thumbnail" referrerpolicy="no-referrer" />
    </picture>
</a>
<p>
<a href="%s">%s</a>
</p>
EOD, $videoUri, $webpSrcset, $jpegSrcset, $fallbackUri, $videoUri, $videoUri);
}
