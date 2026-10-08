<?php

namespace App;

final class Integrity
{
    private array $record;
    private array $structure;
    private array $highlights;
    private array $fileMeta;

    public function __construct(array $record, array $structure, array $highlights, array $fileMeta)
    {
        $this->record = $record;
        $this->structure = $structure;
        $this->highlights = $highlights;
        $this->fileMeta = $fileMeta;
    }

    private function value(array $tags): ?string
    {
        foreach ($tags as $tag) {
            foreach ($this->record as $key => $value) {
                $pos = strrpos($key, ':');
                $name = preg_replace('/_\d+$/', '', $pos === false ? $key : substr($key, $pos + 1));
                if (strcasecmp($name, $tag) === 0 && $value !== '' && $value !== null) {
                    return is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
                }
            }
        }
        return null;
    }

    private function parseDate(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = preg_replace('/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', trim($value));
        $ts = strtotime((string) $normalized);
        return $ts === false ? null : $ts;
    }

    public function analyze(): array
    {
        $findings = [];

        // 1) ملاحظات بنية الملف (بيانات ملحقة، أخطاء CRC...)
        foreach (($this->structure['notes'] ?? []) as $note) {
            $findings[] = ['level' => $note['level'] ?? 'info', 'title' => 'بنية الملف', 'detail' => $note['text'] ?? ''];
        }

        // 2) وجود بيانات EXIF
        if (empty($this->highlights['has_exif'])) {
            $findings[] = ['level' => 'warn', 'title' => 'بيانات EXIF', 'detail' => 'لا توجد بيانات EXIF/IPTC/XMP. قد تكون مُسحت عمداً (لإخفاء المصدر أو الموقع).'];
        } else {
            $findings[] = ['level' => 'ok', 'title' => 'بيانات EXIF', 'detail' => 'الصورة تحتوي على بيانات وصفية قابلة للقراءة.'];
        }

        // 3) بيانات الموقع الجغرافي
        if (!empty($this->highlights['gps']['available'])) {
            $findings[] = ['level' => 'warn', 'title' => 'خصوصية الموقع', 'detail' => 'الصورة تحتوي إحداثيات GPS تكشف مكان التصوير.'];
        }

        // 4) برامج التعديل وسجل XMP
        if (!empty($this->highlights['editing'])) {
            foreach ($this->highlights['editing'] as $e) {
                $findings[] = ['level' => 'warn', 'title' => 'مؤشر تعديل', 'detail' => $e['label'] . ': ' . $e['value']];
            }
        }

        // 5) مقارنة التواريخ
        $dates = [];
        $dateMap = [
            'تاريخ الالتقاط (DateTimeOriginal)' => ['DateTimeOriginal'],
            'تاريخ الإنشاء (CreateDate)' => ['CreateDate'],
            'تاريخ تعديل الميتاداتا (ModifyDate)' => ['ModifyDate'],
            'تاريخ تعديل الملف (نظام الملفات)' => ['FileModifyDate'],
            'تاريخ GPS' => ['GPSDateTime'],
        ];
        foreach ($dateMap as $label => $tags) {
            $raw = $this->value($tags);
            if ($raw !== null) {
                $dates[] = ['label' => $label, 'value' => $raw, 'ts' => $this->parseDate($raw)];
            }
        }
        $byLabel = [];
        foreach ($dates as $d) {
            $byLabel[$d['label']] = $d;
        }
        $original = $byLabel['تاريخ الالتقاط (DateTimeOriginal)']['ts'] ?? null;
        $fileMod = $byLabel['تاريخ تعديل الملف (نظام الملفات)']['ts'] ?? null;
        $exifMod = $byLabel['تاريخ تعديل الميتاداتا (ModifyDate)']['ts'] ?? null;
        $create = $byLabel['تاريخ الإنشاء (CreateDate)']['ts'] ?? null;

        if ($original !== null && $fileMod !== null && $fileMod < $original) {
            $findings[] = ['level' => 'danger', 'title' => 'تناقض زمني', 'detail' => 'تاريخ تعديل الملف يسبق تاريخ الالتقاط — مؤشر على تلاعب بالبيانات الوصفية.'];
        }
        if ($original !== null && $exifMod !== null && abs($exifMod - $original) > 60) {
            $findings[] = ['level' => 'warn', 'title' => 'تناقض زمني', 'detail' => 'تاريخ تعديل الميتاداتا يختلف عن تاريخ الالتقاط (ربما حُرّرت ثم أُعيد حفظها).'];
        }
        if ($original === null && ($create !== null || $exifMod !== null)) {
            $findings[] = ['level' => 'warn', 'title' => 'تاريخ ناقص', 'detail' => 'تاريخ الالتقاط الأصلي (DateTimeOriginal) مفقود.'];
        }
        if ($original !== null && $fileMod !== null && abs($fileMod - $original) > 365 * 86400) {
            $findings[] = ['level' => 'info', 'title' => 'فرق زمني كبير', 'detail' => 'الفرق بين تاريخ الالتقاط وتاريخ تعديل الملف يتجاوز سنة.'];
        }

        // 6) المصغّرة (Thumbnail)
        $thumbOffset = $this->value(['ThumbnailOffset']);
        $thumbLength = $this->value(['ThumbnailLength']);
        $thumbImage = $this->value(['ThumbnailImage']);
        $thumbnail = ['present' => $thumbImage !== null && $thumbImage !== '', 'offset' => $thumbOffset, 'length' => $thumbLength];
        if ($thumbOffset !== null && ($thumbLength === null || (int) $thumbLength === 0) && !$thumbnail['present']) {
            $findings[] = ['level' => 'warn', 'title' => 'مصغّرة منزوعة', 'detail' => 'يوجد مؤشر مصغّرة (ThumbnailOffset) لكن بياناتها محذوفة — أثر شائع لتجريد البيانات.'];
        }

        // 7) عدم تطابق الامتداد مع النوع الفعلي
        $realExt = strtolower((string) $this->value(['FileTypeExtension']));
        $givenExt = strtolower((string) ($this->fileMeta['ext'] ?? ''));
        if ($realExt !== '' && $givenExt !== '' && $realExt !== $givenExt && !($realExt === 'jpeg' && $givenExt === 'jpg')) {
            $findings[] = ['level' => 'warn', 'title' => 'عدم تطابق الامتداد', 'detail' => "الامتداد المرفوع «{$givenExt}» بينما النوع الفعلي «{$realExt}»."];
        }

        // 8) تعليقات / UserComment
        $userComment = $this->value(['UserComment', 'Comment']);
        if ($userComment !== null && $userComment !== '') {
            $findings[] = ['level' => 'info', 'title' => 'تعليق', 'detail' => mb_substr($userComment, 0, 200)];
        }

        // 9) تحذيرات exiftool
        $stored = $this->structure['_exiftool_warnings'] ?? [];
        foreach ($stored as $w) {
            $findings[] = ['level' => 'warn', 'title' => 'تحذير ExifTool', 'detail' => (string) $w];
        }

        $order = ['danger' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];
        usort($findings, static fn ($a, $b) => ($order[$a['level']] ?? 9) <=> ($order[$b['level']] ?? 9));

        $hasDanger = (bool) array_filter($findings, static fn ($f) => $f['level'] === 'danger');
        $hasWarn = (bool) array_filter($findings, static fn ($f) => $f['level'] === 'warn');
        $level = $hasDanger ? 'danger' : ($hasWarn ? 'warn' : 'ok');
        $titles = [
            'danger' => 'ملف مشبوه / معدّل',
            'warn' => 'يتطلب انتباهاً',
            'ok' => 'لا مؤشرات على تلاعب',
        ];

        return [
            'verdict' => ['level' => $level, 'title' => $titles[$level], 'count' => count($findings)],
            'findings' => $findings,
            'dates' => array_map(static fn ($d) => ['label' => $d['label'], 'value' => $d['value']], $dates),
            'thumbnail' => $thumbnail,
        ];
    }
}
