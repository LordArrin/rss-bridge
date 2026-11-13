<?php

declare(strict_types=1);

/**
 * Sanitize image binary data by re-encoding through GD.
 *
 * This function strips EXIF metadata (including GPS coordinates and camera info),
 * neutralizes polyglot attacks (malicious code embedded in image files),
 * and ensures the image is a valid, clean file.
 *
 * SECURITY: this function is fail-closed. If the input cannot be sanitized for any
 * reason (GD missing, undecodable data, encoding failure, ...), it returns a safe
 * 1x1 transparent WebP placeholder instead of the original bytes, so unsanitized
 * content is never passed through.
 *
 * @param string $binary Raw image binary data
 * @param int|null $maxWidth Optional maximum width (null = no resize)
 * @param int|null $maxHeight Optional maximum height (null = no resize)
 * @param int $quality WebP quality (1-100)
 * @return string Sanitized WebP binary, or a safe placeholder on failure
 */
function sanitize_image(
    string $binary,
    ?int $maxWidth = null,
    ?int $maxHeight = null,
    int $quality = 85
): string {
    // Fail-closed: without GD we cannot sanitize, so never return the original.
    if (extension_loaded('gd') === false) {
        return sanitize_image_placeholder();
    }

    // Too small to be a meaningful image; do not pass untrusted bytes through.
    if (strlen($binary) < 100) {
        return sanitize_image_placeholder();
    }

    $sourceImage = @imagecreatefromstring($binary);
    if ($sourceImage === false) {
        return sanitize_image_placeholder();
    }

    $bufferLevel = ob_get_level();

    try {
        $width = imagesx($sourceImage);
        $height = imagesy($sourceImage);

        // Determine target dimensions
        $targetWidth = $width;
        $targetHeight = $height;

        if ($maxWidth !== null && $maxWidth > 0 && $width > $maxWidth) {
            $ratio = $maxWidth / $width;
            $targetWidth = $maxWidth;
            $targetHeight = (int) round($height * $ratio);
        }

        if ($maxHeight !== null && $maxHeight > 0 && $targetHeight > $maxHeight) {
            $ratio = $maxHeight / $targetHeight;
            $targetHeight = $maxHeight;
            $targetWidth = (int) round($targetWidth * $ratio);
        }

        // Hard limit to prevent GD from failing on massive images (e.g., 2304 x 49853).
        // 8192px is more than enough for any web display and keeps memory usage safe.
        // This ensures the image is still sanitized (EXIF/polyglots stripped) rather
        // than failing and returning a placeholder.
        $maxDimension = 8192;
        if ($targetWidth > $maxDimension || $targetHeight > $maxDimension) {
            $ratio = min($maxDimension / $targetWidth, $maxDimension / $targetHeight);
            $targetWidth = (int) round($targetWidth * $ratio);
            $targetHeight = (int) round($targetHeight * $ratio);
        }

        // Clamp to ensure valid dimensions for GD
        $targetWidth = max(1, $targetWidth);
        $targetHeight = max(1, $targetHeight);

        // Create clean canvas
        $cleanImage = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($cleanImage === false) {
            return sanitize_image_placeholder();
        }

        // Preserve transparency
        imagealphablending($cleanImage, false);
        imagesavealpha($cleanImage, true);

        // Resample (kills EXIF and polyglots)
        imagecopyresampled(
            $cleanImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height
        );

        // Output as WebP for best compression
        ob_start();
        imagewebp($cleanImage, null, $quality);
        $sanitized = ob_get_clean();

        if (is_string($sanitized) === false || $sanitized === '') {
            return sanitize_image_placeholder();
        }

        return $sanitized;
    } catch (\Throwable $e) {
        // Make sure a failed encode does not leave an output buffer open.
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        return sanitize_image_placeholder();
    }
}

/**
 * A valid 1x1 transparent WebP used as the fail-closed placeholder.
 */
function sanitize_image_placeholder(): string
{
    // PHPStan strict rules require the second parameter ($strict) to be explicitly set.
    return base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==', true);
}
