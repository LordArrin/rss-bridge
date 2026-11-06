<?php

declare(strict_types=1);

/**
 * Sanitize image binary data by re-encoding through GD.
 *
 * This function strips EXIF metadata (including GPS coordinates and camera info),
 * neutralizes polyglot attacks (malicious code embedded in image files),
 * and ensures the image is a valid, clean file.
 *
 * @param string $binary Raw image binary data
 * @param int|null $maxWidth Optional maximum width (null = no resize)
 * @param int|null $maxHeight Optional maximum height (null = no resize)
 * @param int $quality WebP quality (1-100)
 * @return string Sanitized image binary, or original on failure
 */
function sanitize_image(
    string $binary,
    ?int $maxWidth = null,
    ?int $maxHeight = null,
    int $quality = 85
): string {
    // Skip if GD not available
    if (extension_loaded('gd') === false) {
        return $binary;
    }

    // Skip tiny files (likely corrupted or not images)
    if (strlen($binary) < 100) {
        return $binary;
    }

    // Try to create image from binary
    $sourceImage = @imagecreatefromstring($binary);
    if ($sourceImage === false) {
        return $binary;
    }

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

        // Create clean canvas
        $cleanImage = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($cleanImage === false) {
            return $binary;
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
            return $binary;
        }

        return $sanitized;
    } catch (\Throwable $e) {
        return $binary;
    }
}
