<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Uploader;

$config = require __DIR__ . '/../config/config.php';

$uploader = new Uploader(__DIR__ . '/../storage/uploads', __DIR__ . '/../storage/tmp');

$token = (string) ($_GET['token'] ?? '');
$path = $uploader->resolvePath($token);
if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'not found';
    exit;
}

$meta = $uploader->readMeta($token) ?? [];
$mime = (string) ($meta['mime'] ?? '');
if ($mime === '' || strncmp($mime, 'image/', 6) !== 0) {
    $mime = 'application/octet-stream';
}

$name = preg_replace('/[\r\n"]+/', '', (string) ($meta['name'] ?? 'image'));

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
readfile($path);
