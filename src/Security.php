<?php

namespace App;

final class Security
{
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) $bytes : number_format($value, $value >= 100 ? 0 : 1)) . ' ' . $units[$i];
    }

    /**
     * Validates that the file is a real image using extension + finfo + getimagesize.
     *
     * @return array{ok:bool,error:string,mime:string,ext:string}
     */
    public static function validateUpload(string $path, string $originalName, array $config): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $config['allowed_extensions'], true)) {
            return ['ok' => false, 'error' => 'File extension is not allowed.', 'mime' => '', 'ext' => $ext];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($path);

        $mimeOk = in_array($mime, $config['allowed_mimes'], true) || str_starts_with($mime, 'image/');
        if (!$mimeOk) {
            return ['ok' => false, 'error' => 'The actual file type is not an image (' . self::escape($mime) . ').', 'mime' => $mime, 'ext' => $ext];
        }

        // getimagesize handles common formats; ignore its failure for modern ones (heic/avif).
        $info = @getimagesize($path);
        if ($info === false) {
            $check = Runner::run(['exiftool', '-s3', '-FileType', $path], 10, 4096);
            $type = trim($check['stdout']);
            if ($type === '') {
                return ['ok' => false, 'error' => 'Unable to identify this file as a valid image.', 'mime' => $mime, 'ext' => $ext];
            }
        }

        return ['ok' => true, 'error' => '', 'mime' => $mime, 'ext' => $ext];
    }

    /**
     * Simple session-based rate limiting.
     *
     * @return array{ok:bool,remaining:int,reset_in:int}
     */
    public static function rateLimit(array $config): array
    {
        $max = (int) $config['rate_limit']['max'];
        $window = (int) $config['rate_limit']['window_seconds'];
        $now = time();
        $bucket = $_SESSION['rl'] ?? ['start' => $now, 'count' => 0];
        if (($now - $bucket['start']) > $window) {
            $bucket = ['start' => $now, 'count' => 0];
        }
        if ($bucket['count'] >= $max) {
            return ['ok' => false, 'remaining' => 0, 'reset_in' => $window - ($now - $bucket['start'])];
        }
        $bucket['count']++;
        $_SESSION['rl'] = $bucket;
        return ['ok' => true, 'remaining' => $max - $bucket['count'], 'reset_in' => $window - ($now - $bucket['start'])];
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
