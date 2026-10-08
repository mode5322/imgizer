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
    Security::json(['ok' => false, 'error' => 'طريقة الطلب غير مسموحة.'], 405);
}

if (!Security::checkCsrf($_POST['csrf'] ?? null)) {
    Security::json(['ok' => false, 'error' => 'انتهت صلاحية الجلسة، أعد تحميل الصفحة.'], 419);
}

if ($config['access_password'] !== '') {
    $given = (string) ($_POST['password'] ?? ($_SERVER['HTTP_X_ACCESS_PASSWORD'] ?? ''));
    if ($given === '' || !hash_equals((string) $config['access_password'], $given)) {
        Security::json(['ok' => false, 'error' => 'كلمة المرور غير صحيحة.'], 403);
    }
}

if ($config['captcha_question']) {
    $answer = (int) ($_POST['captcha'] ?? -1);
    if (empty($_SESSION['captcha_answer']) || $answer !== (int) $_SESSION['captcha_answer']) {
        Security::json(['ok' => false, 'error' => 'إجابة التحقق البشري غير صحيحة.'], 422);
    }
    unset($_SESSION['captcha_answer']);
}

$limit = Security::rateLimit($config);
if (!$limit['ok']) {
    Security::json(['ok' => false, 'error' => 'تم تجاوز الحد المسموح من التحليلات. حاول لاحقاً.'], 429);
}

if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    Security::json(['ok' => false, 'error' => 'لم يتم استلام أي صورة.'], 400);
}

$file = $_FILES['image'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'حجم الملف يتجاوز الحد المسموح في إعدادات PHP.',
        UPLOAD_ERR_FORM_SIZE => 'حجم الملف يتجاوز الحد المسموح.',
        UPLOAD_ERR_PARTIAL => 'لم يكتمل رفع الملف.',
        UPLOAD_ERR_NO_FILE => 'لم يتم اختيار ملف.',
        UPLOAD_ERR_NO_TMP_DIR => 'مجلد الملفات المؤقتة غير متاح.',
        UPLOAD_ERR_CANT_WRITE => 'تعذّر كتابة الملف على القرص.',
        UPLOAD_ERR_EXTENSION => 'أوقف امتداد PHP عملية الرفع.',
    ];
    Security::json(['ok' => false, 'error' => $messages[$file['error']] ?? 'فشل رفع الملف.'], 400);
}

if (($file['size'] ?? 0) > (int) $config['max_upload_bytes']) {
    Security::json(['ok' => false, 'error' => 'حجم الصورة يتجاوز الحد المسموح (' . Security::humanBytes((int) $config['max_upload_bytes']) . ').'], 413);
}

$originalName = (string) ($file['name'] ?? 'image');
$check = Security::validateUpload((string) $file['tmp_name'], $originalName, $config);
if (!$check['ok']) {
    Security::json(['ok' => false, 'error' => $check['error']], 400);
}

$stored = $uploader->store((string) $file['tmp_name'], $check['ext']);
if ($stored === null) {
    Security::json(['ok' => false, 'error' => 'تعذّر حفظ الصورة على الخادم.'], 500);
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
    Security::json(['ok' => false, 'error' => 'حدث خطأ أثناء التحليل: ' . $e->getMessage()], 500);
}
