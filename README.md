# محلّل بيانات الصور (Image Metadata Analyzer)

صفحة ويب بلغة **PHP** تستقبل صورة وتعرض تحليلاً متقدماً لها باستخدام مجموعة أدوات احترافية:
بيانات EXIF الكاملة، بنية الملف، الملفات المضمّنة/المخفية، تحليل التخفي (Steganography)،
الإنتروبيا، وكشف مؤشرات التعديل والتلاعب.

---

## المزايا

- **EXIF كامل** عبر ExifTool مع تجميع الوسوم وإمكانية التصفية والبحث.
- **Exiv2** لقراءة IPTC/XMP/EXIF من زاوية أخرى.
- **ImageMagick** لخصائص الصورة (مساحة الألوان، العمق، الضغط، عدد الإطارات...).
- **Binwalk** لكشف التوقيعات والملفات المضمّنة أو الملحقة.
- **Strings** لاستخراج الروابط والبُرد والنصوص المثيرة للاهتمام.
- **التخفي**: zsteg (PNG/BMP) + steghide (JPEG/BMP) + تحليل البتات الأقل أهمية (LSB).
- **إنتروبيا الملف** مع رسم بياني تفاعلي للكشف عن محتوى مشفّر/مخفي.
- **سلامة الملف وكشف التعديل**: بيانات ملحقة بعد نهاية الصورة، أخطاء CRC،
  تناقض التواريخ، نزع المصغّرات، برامج التعديل وسجل XMP، نزع بيانات EXIF.
- **الخصوصية**: استخراج إحداثيات GPS مع روابط الخرائط، ورصد الحقول الحساسة.
- واجهة عربية RTL، سحب وإفلات، معاينة محلية، تنزيل النتائج، وحماية بالجلسة/كلمة مرور.

---

## التقنيات والمتطلبات

| المكوّن | الغرض | إلزامي |
|---|---|---|
| PHP ≥ 8.0 (مع `fileinfo` و`exif`) | تشغيل التطبيق | نعم |
| ExifTool | قراءة البيانات الوصفية | نعم (الأساس) |
| ImageMagick (`identify`/`magick`) | خصائص الصورة وتحليل LSB | نعم |
| Exiv2 | قراءة IPTC/XMP | مستحسن |
| Binwalk | التوقيعات والملفات المضمّنة | مستحسن |
| Binutils (`strings`) | استخراج النصوص | مستحسن |
| zsteg (Ruby) | تخفي PNG/BMP | اختياري |
| steghide | تخفي JPEG/BMP | اختياري |

> التطبيق يعمل حتى مع غياب الأدوات الاختيارية، ويعرض حالتها في أعلى الصفحة.

---

## التثبيت السريع

```bash
# 1) استنسخ المشروع من GitHub على الـ VPS
git clone https://github.com/<username>/<repo>.git
cd <repo>

# 2) ثبّت الاعتماديات وأعدّ Nginx (Debian/Ubuntu/Kali)
sudo ./install.sh --nginx --domain your-domain.com

# أو بدون Nginx (للتجربة فقط)
sudo ./install.sh
php -S 0.0.0.0:8080 -t public
```

ثم افتح المتصفح على نطاقك.

---

## النشر عبر GitHub (كما اخترت)

```bash
# على جهازك المحلي: ارفع المشروع
git init
git add .
git commit -m "Initial commit: image metadata analyzer"
git branch -M main
git remote add origin https://github.com/<username>/<repo>.git
git push -u origin main
```

```bash
# على الـ VPS: استرجع المشروع
git clone https://github.com/<username>/<repo>.git
cd <repo>
sudo ./install.sh --nginx --domain your-domain.com

# لتحديث لاحق
git pull && sudo systemctl reload php*-fpm nginx
```

---

## الإعدادات

كل الإعدادات في `config/config.php`. لتجاوزها دون تعديل المستودع أنشئ
`config/config.local.php` (مستثنى من Git) يعيد مصفوفة بالتعديلات:

```php
<?php
return [
    // 'access_password' => 'كلمة-مرور-قوية',
    'max_upload_bytes' => 25 * 1024 * 1024,
    'captcha_question' => true,
    'retention_hours' => 6,
];
```

| الإعداد | الوصف |
|---|---|
| `max_upload_bytes` | الحد الأقصى لحجم الصورة |
| `access_password` | كلمة مرور اختيارية لحماية الصفحة (فارغة = بدون حماية) |
| `captcha_question` | سؤال حسابي بسيط لمنع الروبوتات |
| `command_timeout` | مهلة كل أداة بالثواني |
| `rate_limit` | عدد التحليلات لكل جلسة خلال نافذة زمنية |
| `retention_hours` | مدة بقاء الملفات المرفوعة قبل الحذف التلقائي |
| `tool_paths` | مسارات مخصّصة للأدوات (اتركها فارغة للاكتشاف التلقائي) |

---

## بنية المشروع

```
.
├── config/config.php        # الإعدادات
├── public/                  # جذر الويب
│   ├── index.php            # الصفحة
│   ├── analyze.php          # endpoint التحليل
│   ├── preview.php          # عرض الصورة المرفوعة
│   └── assets/              # CSS/JS
├── src/                     # منطق التطبيق (PSR-like، بدون Composer)
│   ├── Analyzer.php         # المنسّق العام
│   ├── Runner.php           # تشغيل الأوامر بأمان
│   ├── Security.php         # التحقق وحماية الرفع
│   ├── Uploader.php         # تخزين الملفات المؤقت
│   ├── Structure.php        # تحليل بنية JPEG/PNG/GIF
│   ├── Entropy.php          # الإنتروبيا وتحليل LSB
│   ├── Highlights.php       # أهم الوسوم
│   ├── Integrity.php        # كشف التعديل
│   └── Tools/               # مغلّفات الأدوات
├── storage/                 # ملفات مؤقتة (مستثناة من Git)
├── deploy/nginx.conf        # إعداد Nginx مرجعي
└── install.sh               # سكربت التثبيت
```

---

## ملاحظات أمنية

- لا تُنفَّذ الملفات المرفوعة إطلاقاً، وتُخزَّن باسم عشوائي خارج جذر الويب.
- كل أوامر النظام تُشغَّل كمصفوفة معاملات (`proc_open` بدون shell) لتفادي حقن الأوامر.
- تحقق مزدوج من الملف: الامتداد + `finfo` + التحقق من صحة الصورة.
- رؤوس أمان (CSP, X-Frame-Options, nosniff) وحماية CSRF ومعدّل طلبات.
- الملفات تُحذف تلقائياً حسب `retention_hours`.
- **موصى به للإنتاج:** ضع الصفحة خلف HTTPS وشغّل `access_password`.

---

## استكشاف الأخطاء

- **الفشل في رفع صور كبيرة:** تأكد من `upload_max_filesize` و`post_max_size` في PHP
  و`client_max_body_size` في Nginx.
- **binwalk: Entropy Exception (numba):** وحدة إنتروبيا binwalk معطّلة في بعض الأنظمة؛
  التطبيق يحسب الإنتروبيا داخلياً ولن يتأثر.
- **zsteg غير مكتشف:** تأكد من `gem install zsteg` وأن أمر `zsteg` ضمن `PATH`،
  أو حدّد مساره في `tool_paths`.
- **لا تظهر بيانات EXIF:** الصورة قد تكون مُسحت بياناتها (وهذا بحد ذاته مؤشر مهم).
```
