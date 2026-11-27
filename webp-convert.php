<?php
if (!defined('ABSPATH')) exit;

/**
 * Converte imagens para WebP automaticamente ao fazer upload.
 * Aplica aos tamanhos gerados (thumbnail, medium, etc.) e ao arquivo original.
 */
add_filter('image_editor_output_format', function (array $mappings): array {
    $mappings['image/jpeg'] = 'image/webp';
    $mappings['image/png']  = 'image/webp';
    return $mappings;
});

// Converte o arquivo original para WebP após o upload
add_filter('wp_handle_upload', function (array $upload): array {
    if (!in_array($upload['type'] ?? '', ['image/jpeg', 'image/png'], true)) {
        return $upload;
    }

    $source = $upload['file'];
    $dest   = preg_replace('/\.(jpe?g|png)$/i', '.webp', $source);

    if ($dest === $source) return $upload;

    $converted = false;
    $lower     = strtolower($source);

    if (function_exists('imagecreatefromjpeg') && (str_ends_with($lower, '.jpg') || str_ends_with($lower, '.jpeg'))) {
        $img = @imagecreatefromjpeg($source);
        if ($img) {
            $converted = imagewebp($img, $dest, 82);
            imagedestroy($img);
        }
    } elseif (function_exists('imagecreatefrompng') && str_ends_with($lower, '.png')) {
        $img = @imagecreatefrompng($source);
        if ($img) {
            imagealphablending($img, true);
            imagesavealpha($img, true);
            $converted = imagewebp($img, $dest, 82);
            imagedestroy($img);
        }
    }

    if ($converted) {
        @unlink($source);
        $upload['file'] = $dest;
        $upload['url']  = str_replace(basename($source), basename($dest), $upload['url']);
        $upload['type'] = 'image/webp';
    }

    return $upload;
});
