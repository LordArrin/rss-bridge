<?php

/**
 * Convert Markdown into HTML with Parsedown.
 */
function markdownToHtml($string, $config = [])
{
    $Parsedown = new Parsedown();
    foreach ($config as $option => $value) {
        if ($option === 'breaksEnabled') {
            $Parsedown->setBreaksEnabled($value);
        } elseif ($option === 'markupEscaped') {
            $Parsedown->setMarkupEscaped($value);
        } elseif ($option === 'urlsLinked') {
            $Parsedown->setUrlsLinked($value);
        } else {
            throw new \InvalidArgumentException("Invalid Parsedown option \"$option\"");
        }
    }
    return $Parsedown->text($string);
}

/**
 * Handle a YouTube video by returning an iframe or a clickable thumbnail.
 */
function handleYoutube(string $string)
{
    $useIframe = Configuration::getConfig('youtube', 'iframe');
    $useNocookie = Configuration::getConfig('youtube', 'nocookie');

    $regex = '#(?:https?://|//)?(?:www\.|m\.|.+\.)?(?:youtu\.be/|youtube(?:-nocookie|)\.com/(?:embed/|v/|shorts/|feeds/api/videos/|watch\?v=|watch\?.+&v=))([\w-]{11})#i';
    if (preg_match($regex, $string, $matches) === 1) {
        $videoID = $matches[1];
    } elseif (preg_match('#[\w-]{11}#i', $string, $matches2) === 1) {
        $videoID = $matches2[0];
    } else {
        return '';
    }

    if ($useIframe) {
        if ($useNocookie) {
            $embedUri = 'https://www.youtube-nocookie.com/embed/' . $videoID;
        } else {
            $embedUri = 'https://www.youtube.com/embed/' . $videoID;
        }

        return sprintf(<<<EOD
<iframe width="560" height="315" src="%s" title="YouTube video player" frameborder="0"
allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
referrerpolicy="strict-origin" allowfullscreen></iframe>'
EOD
         , $embedUri);
    } else {
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
}
