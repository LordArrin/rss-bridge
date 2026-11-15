<?php

declare(strict_types=1);

/**
 * @return array{0: int, 1: int}
 */
function sanitize_image_target_size(int $width, int $height, ?int $maxWidth, ?int $maxHeight): array
{
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

    $maxDimension = 8192;
    if ($targetWidth > $maxDimension || $targetHeight > $maxDimension) {
        $ratio = min($maxDimension / $targetWidth, $maxDimension / $targetHeight);
        $targetWidth = (int) round($targetWidth * $ratio);
        $targetHeight = (int) round($targetHeight * $ratio);
    }

    return [max(1, $targetWidth), max(1, $targetHeight)];
}

/**
 * Sanitize image binary data by re-encoding through GD.
 *
 * This function strips EXIF metadata (including GPS coordinates and camera info),
 * neutralizes polyglot attacks (malicious code embedded in image files),
 * and ensures the image is a valid, clean file.
 *
 * SECURITY: undecodable or unencodable input never passes through: a safe 1x1
 * transparent WebP placeholder is returned instead. The single exception is the
 * memory guard: when there is not enough headroom to re-encode, the original
 * bytes are passed through unchanged rather than replaced with a placeholder.
 *
 * Memory safety: dimensions are read from the header WITHOUT decoding pixels, and
 * the decode is only attempted when the estimated GD memory fits within the
 * available headroom (PHP memory_limit, cgroup limit and host MemAvailable).
 *
 * @param string $binary Raw image binary data
 * @param int|null $maxWidth Optional maximum width (null = no resize)
 * @param int|null $maxHeight Optional maximum height (null = no resize)
 * @param int $quality WebP quality (1-100)
 * @return string Sanitized WebP binary, original bytes on low memory, or a safe placeholder on failure
 */
function sanitize_image(
    string $binary,
    ?int $maxWidth = null,
    ?int $maxHeight = null,
    int $quality = 85
): string {
    if (extension_loaded('gd') === false) {
        return sanitize_image_placeholder();
    }

    if (strlen($binary) < 26) {
        return sanitize_image_placeholder();
    }

    $width = 0;
    $height = 0;
    $targetWidth = 0;
    $targetHeight = 0;

    $info = @getimagesizefromstring($binary);
    if ($info !== false && $info[0] > 0 && $info[1] > 0) {
        if ($info[0] > 65535 || $info[1] > 65535) {
            return sanitize_image_placeholder();
        }
        $width = $info[0];
        $height = $info[1];
        [$targetWidth, $targetHeight] = sanitize_image_target_size($width, $height, $maxWidth, $maxHeight);
        $estimated = (int) ((($width * $height) + ($targetWidth * $targetHeight)) * 4 * 1.25);
        if ($estimated > sanitize_image_available_memory()) {
            return $binary;
        }
    }

    $sourceImage = @imagecreatefromstring($binary);
    if ($sourceImage === false) {
        return sanitize_image_placeholder();
    }

    if ($width === 0) {
        $width = imagesx($sourceImage);
        $height = imagesy($sourceImage);
        [$targetWidth, $targetHeight] = sanitize_image_target_size($width, $height, $maxWidth, $maxHeight);
    }

    $targetWidth = max(1, $targetWidth);
    $targetHeight = max(1, $targetHeight);

    $bufferLevel = ob_get_level();

    try {
        $cleanImage = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($cleanImage === false) {
            return sanitize_image_placeholder();
        }

        imagealphablending($cleanImage, false);
        imagesavealpha($cleanImage, true);

        imagecopyresampled(
            $cleanImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            imagesx($sourceImage),
            imagesy($sourceImage)
        );

        ob_start();
        $ok = imagewebp($cleanImage, null, $quality);
        $sanitized = ob_get_clean();

        if ($ok === false || is_string($sanitized) === false || $sanitized === '') {
            return sanitize_image_placeholder();
        }

        return $sanitized;
    } catch (\Throwable) {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        return sanitize_image_placeholder();
    }
}

/**
 * Parses a PHP shorthand byte value ("128M", "1G", "134217728") into bytes.
 * Returns -1 for unlimited/unknown ("-1" or empty).
 */
function sanitize_image_parse_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return -1;
    }
    $unit = strtolower(substr($value, -1));
    $number = (int) $value;
    return match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
}

/**
 * Estimates the bytes available for image processing, taking the minimum of:
 *  - PHP memory_limit headroom (limit - current real usage),
 *  - cgroup memory headroom (container-aware; /proc/meminfo alone shows the host),
 *  - host MemAvailable (Linux).
 * A 10% reserve is kept for PHP overhead, output buffering and WebP encoding.
 * Falls back to a conservative 64 MiB when nothing is readable.
 */
function sanitize_image_available_memory(): int
{
    $candidates = [];

    // 1. PHP memory_limit headroom.
    $limit = sanitize_image_parse_bytes((string) ini_get('memory_limit'));
    if ($limit > 0) {
        $candidates[] = $limit - memory_get_usage(true);
    }

    // 2. cgroup memory headroom (v2 then v1).
    $cgroupLimit = null;
    $cgroupUsage = 0;
    if (is_readable('/sys/fs/cgroup/memory.max') === true) {
        $max = trim((string) @file_get_contents('/sys/fs/cgroup/memory.max'));
        $cgroupLimit = $max === 'max' ? null : (int) $max;
        $cgroupUsage = (int) trim((string) @file_get_contents('/sys/fs/cgroup/memory.current'));
    } elseif (is_readable('/sys/fs/cgroup/memory/memory.limit_in_bytes') === true) {
        $max = (int) trim((string) @file_get_contents('/sys/fs/cgroup/memory/memory.limit_in_bytes'));
        // A near-PHP_INT_MAX value means "unlimited".
        $cgroupLimit = ($max > 0 && $max < PHP_INT_MAX / 2) ? $max : null;
        $cgroupUsage = (int) trim((string) @file_get_contents('/sys/fs/cgroup/memory/memory.usage_in_bytes'));
    }
    if ($cgroupLimit !== null && $cgroupLimit > 0) {
        $candidates[] = $cgroupLimit - $cgroupUsage;
    }

    // 3. Host MemAvailable (Linux).
    if (is_readable('/proc/meminfo') === true) {
        $meminfo = (string) @file_get_contents('/proc/meminfo');
        if (preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $meminfo, $m) === 1) {
            $candidates[] = ((int) $m[1]) * 1024;
        }
    }

    $candidates = array_filter($candidates, static fn (int $v): bool => $v > 0);
    if ($candidates === []) {
        return 64 * 1024 * 1024;
    }

    return (int) (min($candidates) * 0.9);
}

/**
 * A valid 1x1 transparent WebP used as the fail-closed placeholder.
 */
function sanitize_image_placeholder(): string
{
    // PHPStan strict rules require the second parameter ($strict) to be explicitly set.
    return (string) base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==', true);
}
