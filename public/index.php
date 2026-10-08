<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/../src/autoload.php';

use App\Runner;
use App\Security;

$config = require __DIR__ . '/../config/config.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

$csrf = Security::csrfToken();

$a = random_int(2, 9);
$b = random_int(2, 9);
$_SESSION['captcha_answer'] = $a + $b;

$toolMap = [
    'exiftool' => 'ExifTool',
    'exiv2' => 'Exiv2',
    'identify' => 'ImageMagick',
    'binwalk' => 'Binwalk',
    'strings' => 'Strings',
    'zsteg' => 'zsteg',
    'steghide' => 'steghide',
];
$availability = [];
foreach ($toolMap as $bin => $label) {
    $availability[$label] = Runner::which($bin, (string) ($config['tool_paths'][$bin] ?? '')) !== null;
}
$imageMagickOk = $availability['ImageMagick'];
if (!$imageMagickOk) {
    $availability['ImageMagick'] = Runner::which('magick', (string) ($config['tool_paths']['magick'] ?? '')) !== null;
}

$appConfig = [
    'maxUploadBytes' => (int) $config['max_upload_bytes'],
    'maxUploadHuman' => Security::humanBytes((int) $config['max_upload_bytes']),
    'requirePassword' => $config['access_password'] !== '',
    'requireCaptcha' => (bool) $config['captcha_question'],
    'csrf' => $csrf,
    'captcha' => $config['captcha_question'] ? ['a' => $a, 'b' => $b] : null,
    'allowedExtensions' => $config['allowed_extensions'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= Security::escape((string) $config['app_name']) ?></title>
<link rel="stylesheet" href="assets/style.css?v=1">
</head>
<body>
<header class="site-header">
    <div class="brand">
        <span class="brand-mark">EXIF</span>
        <div>
            <h1><?= Security::escape((string) $config['app_name']) ?></h1>
            <p>تحليل متقدم للصور: بيانات EXIF، البنية، الملفات المخفية، التخفي، وكشف التعديلات.</p>
        </div>
    </div>
    <div class="tool-badges">
        <?php foreach ($availability as $label => $ok): ?>
            <span class="badge <?= $ok ? 'badge-ok' : 'badge-off' ?>" title="<?= $ok ? 'مثبتة' : 'غير مثبتة' ?>">
                <?= Security::escape($label) ?>
            </span>
        <?php endforeach; ?>
    </div>
</header>

<main>
    <section class="panel uploader" id="uploader">
        <form id="analyze-form" autocomplete="off">
            <div class="dropzone" id="dropzone" tabindex="0">
                <input type="file" id="file-input" accept="image/*,.jpg,.jpeg,.png,.gif,.bmp,.webp,.tif,.tiff,.heic,.avif,.ico" hidden>
                <div class="dropzone-inner" id="dropzone-inner">
                    <div class="dz-icon">&#128247;</div>
                    <p class="dz-title">اسحب وأفلت الصورة هنا</p>
                    <p class="dz-sub">أو انقر للاختيار، أو الصق من الحافظة (Ctrl+V)</p>
                    <p class="dz-limits">الحد الأقصى: <?= Security::escape(Security::humanBytes((int) $config['max_upload_bytes'])) ?></p>
                </div>
                <img id="local-preview" alt="معاينة" hidden>
            </div>

            <div class="form-row">
                <?php if ($config['access_password'] !== ''): ?>
                <label class="field">
                    <span>كلمة المرور</span>
                    <input type="password" id="password" required>
                </label>
                <?php endif; ?>
                <?php if ($config['captcha_question']): ?>
                <label class="field small">
                    <span>تحقق بشري: <?= $a ?> + <?= $b ?> =</span>
                    <input type="number" id="captcha" inputmode="numeric" required>
                </label>
                <?php endif; ?>
                <button type="submit" id="submit-btn" disabled>تحليل الصورة</button>
            </div>
            <div class="file-selected" id="file-selected" hidden>
                <span id="selected-name"></span>
                <span id="selected-size" class="muted"></span>
            </div>
            <div class="alert" id="error-box" hidden></div>
        </form>
    </section>

    <section class="loading" id="loading" hidden>
        <div class="spinner"></div>
        <p id="loading-text">جارٍ تشغيل أدوات التحليل...</p>
        <p class="muted" id="loading-timer">0.0 ث</p>
    </section>

    <section id="results" hidden>
        <div class="summary panel" id="summary"></div>
        <nav class="tabs" id="tabs"></nav>
        <div class="tab-content" id="tab-content"></div>
    </section>
</main>

<footer class="site-footer">
    <span>يعمل بقوة ExifTool · Exiv2 · ImageMagick · Binwalk · Strings · zsteg · steghide</span>
</footer>

<script type="application/json" id="app-config"><?= json_encode($appConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="assets/app.js?v=1"></script>
</body>
</html>
