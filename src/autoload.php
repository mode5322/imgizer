<?php

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/**
 * Fallback for mbstring functions when the extension is not installed.
 */
if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        if (function_exists('iconv_substr')) {
            return $length === null ? iconv_substr($string, $start) : iconv_substr($string, $start, $length);
        }
        $result = $length === null ? substr($string, $start) : substr($string, $start, $length);
        return (string) preg_replace('/[\x80-\xBF]$/', '', $result);
    }
}

