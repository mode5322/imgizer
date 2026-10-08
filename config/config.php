<?php

/**
 * إعدادات التطبيق الرئيسية.
 * لكي لا تفقد إعداداتك عند تحديث المستودع، ضع تعديلاتك في config/config.local.php
 * وسيتم دمجها فوق هذه القيم تلقائياً.
 */

$config = [
    'app_name' => 'محلّل بيانات الصور',

    // الحد الأقصى لحجم الصورة المرفوعة (بايت)
    'max_upload_bytes' => 25 * 1024 * 1024,

    // امتدادات مسموحة (متوافقة مع أنواع الصور الشائعة)
    'allowed_extensions' => [
        'jpg', 'jpeg', 'jpe', 'png', 'gif', 'bmp', 'webp',
        'tif', 'tiff', 'heic', 'heif', 'avif', 'ico', 'jp2', 'jxl',
    ],

    // أنواع MIME مسموحة (يتم التحقق منها عبر finfo)
    'allowed_mimes' => [
        'image/jpeg', 'image/png', 'image/gif', 'image/bmp',
        'image/webp', 'image/tiff', 'image/heic', 'image/heif',
        'image/avif', 'image/x-icon', 'image/vnd.microsoft.icon',
        'image/jp2', 'image/jxl', 'image/x-canon-cr2', 'image/x-nikon-nef',
        'image/x-canon-cr3', 'image/x-sony-arw', 'image/x-adobe-dng',
    ],

    // مهلة كل أداة بالثواني
    'command_timeout' => 40,

    // الحد الأقصى لحجم الخرج الخام المحفوظ لكل أداة (بايت)
    'max_raw_bytes' => 768 * 1024,

    // مدة الاحتفاظ بالملفات المرفوعة (بالساعات) قبل الحذف التلقائي
    'retention_hours' => 6,

    // كلمة مرور اختيارية لحماية الصفحة (اتركها فارغة لتعطيل الحماية)
    'access_password' => '',

    // حد المعدل: عدد التحليلات المسموحة لكل جلسة خلال النافذة الزمنية
    'rate_limit' => [
        'max' => 40,
        'window_seconds' => 3600,
    ],

    // مسار عتبة عدّاد حماية الروبوتات (اتركها فارغة لتعطيلها)
    'captcha_question' => true,

    // مسارات أدوات مخصّصة (اترك قدر الإمكان فارغاً للاكتشاف التلقائي)
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
