<?php

/**
 * Main application settings.
 * To keep your settings across repo updates, put changes in config/config.local.php;
 * it is merged over these defaults automatically.
 */

$config = [
    'app_name' => 'Image Metadata Analyzer',

    // Maximum size of an uploaded image (bytes)
    'max_upload_bytes' => 25 * 1024 * 1024,

    // Allowed extensions (common image types)
    'allowed_extensions' => [
        'jpg', 'jpeg', 'jpe', 'png', 'gif', 'bmp', 'webp',
        'tif', 'tiff', 'heic', 'heif', 'avif', 'ico', 'jp2', 'jxl',
    ],

    // Allowed MIME types (validated via finfo)
    'allowed_mimes' => [
        'image/jpeg', 'image/png', 'image/gif', 'image/bmp',
        'image/webp', 'image/tiff', 'image/heic', 'image/heif',
        'image/avif', 'image/x-icon', 'image/vnd.microsoft.icon',
        'image/jp2', 'image/jxl', 'image/x-canon-cr2', 'image/x-nikon-nef',
        'image/x-canon-cr3', 'image/x-sony-arw', 'image/x-adobe-dng',
    ],

    // Timeout per tool in seconds
    'command_timeout' => 40,

    // Max raw output kept per tool (bytes)
    'max_raw_bytes' => 768 * 1024,

    // Retention time for uploaded files (hours) before auto-delete
    'retention_hours' => 6,

    // Optional password protecting the page (empty = disabled)
    'access_password' => '',

    // Rate limit: allowed analyses per session in the time window
    'rate_limit' => [
        'max' => 40,
        'window_seconds' => 3600,
    ],

    // Captcha counter path (leave empty to disable)
    'captcha_question' => true,

    // Custom tool paths (leave empty for auto-detection)
    'tool_paths' => [
        'exiftool' => '',
        'exiv2' => '',
        'identify' => '',
        'magick' => '',
        'binwalk' => '',
        'strings' => '',
        'zsteg' => '',
        'steghide' => '',
    ],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) {
        $config = array_replace_recursive($config, $override);
    }
}

return $config;
