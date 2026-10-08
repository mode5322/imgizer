<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/../src/autoload.php';

use App\Analyzer;
use App\Security;
use App\Uploader;

$config = require __DIR__ . '/../config/config.php';

$storageDir = __DIR__ . '/../storage/uploads';
$tmpDir = __DIR__ . '/../storage/tmp';
$uploader = new Uploader($storageDir, $tmpDir);
$uploader->cleanup((int) $config['retention_hours']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Security::json(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
    Security::json(['ok' => false, 'error' => 'Session expired, please reload the page.'], 419);
}

if ($config['access_password'] !== '') {
    $given = (string) ($_POST['password'] ?? ($_SERVER['HTTP_X_ACCESS_PASSWORD'] ?? ''));
    if ($given === '' || !hash_equals((string) $config['access_password'], $given)) {
        Security::json(['ok' => false, 'error' => 'Invalid password.'], 403);
    }
}

if ($config['captcha_question']) {
    $answer = (int) ($_POST['captcha'] ?? -1);
    if (empty($_SESSION['captcha_answer']) || $answer !== (int) $_SESSION['captcha_answer']) {
        Security::json(['ok' => false, 'error' => 'Incorrect human-check answer.'], 422);
    }
    unset($_SESSION['captcha_answer']);
}

$limit = Security::rateLimit($config);
if (!$limit['ok']) {
    Security::json(['ok' => false, 'error' => 'Analysis rate limit reached. Try again later.'], 429);
}

if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    Security::json(['ok' => false, 'error' => 'No image was received.'], 400);
}

$file = $_FILES['image'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'File size exceeds the limit set in PHP settings.',
        UPLOAD_ERR_FORM_SIZE => 'File size exceeds the allowed limit.',
        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory is missing.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write the file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
    ];
    Security::json(['ok' => false, 'error' => $messages[$file['error']] ?? 'Upload failed.'], 400);
}

if (($file['size'] ?? 0) > (int) $config['max_upload_bytes']) {
    Security::json(['ok' => false, 'error' => 'Image exceeds the size limit (' . Security::humanBytes((int) $config['max_upload_bytes']) . ').'], 413);
}

$originalName = (string) ($file['name'] ?? 'image');
$check = Security::validateUpload((string) $file['tmp_name'], $originalName, $config);
if (!$check['ok']) {
    Security::json(['ok' => false, 'error' => $check['error']], 400);
}

$stored = $uploader->store((string) $file['tmp_name'], $check['ext']);
if ($stored === null) {
    Security::json(['ok' => false, 'error' => 'Failed to store the image on the server.'], 500);
}

$info = @getimagesize($stored['path']);
$meta = [
    'token' => $stored['token'],
    'name' => preg_replace('/[^\p{L}\p{N}._\- ()]+/u', '_', $originalName) ?: 'image',
    'mime' => $check['mime'],
    'ext' => strtolower($check['ext']),
    'size' => (int) ($file['size'] ?? filesize($stored['path'])),
    'width' => is_array($info) ? (int) $info[0] : 0,
    'height' => is_array($info) ? (int) $info[1] : 0,
    'uploaded_at' => time(),
];
$uploader->writeMeta($stored['token'], $meta);

try {
    $analyzer = new Analyzer($config);
    $result = $analyzer->analyze($stored['path'], $meta);
    $result['meta']['rate_remaining'] = $limit['remaining'];
    Security::json($result);
} catch (\Throwable $e) {
    Security::json(['ok' => false, 'error' => 'Analysis failed: ' . $e->getMessage()], 500);
}