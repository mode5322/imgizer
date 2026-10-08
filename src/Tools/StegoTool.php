<?php

namespace App\Tools;

use App\Runner;

final class StegoTool extends BaseTool
{
    public function id(): string
    {
        return 'stego';
    }

    public function name(): string
    {
        return 'أدوات التخفي (Steganography)';
    }

    public function icon(): string
    {
        return "\u{1F575}";
    }

    protected function run(): array
    {
        $result = [
            'zsteg' => $this->runZsteg(),
            'steghide' => $this->runSteghide(),
        ];

        $flags = count($result['zsteg']['findings'] ?? []) + (($result['steghide']['embedded'] ?? false) ? 1 : 0);

        return [
            'status' => 'ok',
            'note' => $flags > 0 ? "{$flags} مؤشر محتمل للتخفي" : 'لم تُكتشف مؤشرات تخفي واضحة',
            'data' => $result,
            'raw' => trim(($result['zsteg']['raw'] ?? '') . "\n\n" . ($result['steghide']['raw'] ?? '')),
        ];
    }

    private function runZsteg(): array
    {
        $bin = $this->bin('zsteg');
        if (!$bin) {
            return ['available' => false, 'note' => 'zsteg غير مثبت (مطلوب للصور PNG/BMP)', 'findings' => [], 'raw' => ''];
        }
        if (!in_array($this->mime, ['image/png', 'image/bmp', 'image/x-ms-bmp', 'image/x-icon'], true)) {
            return ['available' => true, 'note' => 'يدعم zsteg صور PNG/BMP فقط', 'findings' => [], 'raw' => ''];
        }

        $res = Runner::run([$bin, '-a', $this->file], $this->timeout(), $this->maxRaw());
        $findings = [];
        $interesting = 0;
        foreach (preg_split('/\r?\n/', $res['stdout']) as $line) {
            if (!str_contains($line, ' .. ')) {
                continue;
            }
            [$label, $value] = explode(' .. ', $line, 2);
            $label = trim($label);
            $rawValue = trim(preg_replace('/\s+/', ' ', trim($value)) ?: '');
            if ($rawValue === '' || $label === '') {
                continue;
            }
            $value = mb_substr($rawValue, 0, 150);
            if (strlen($rawValue) > 150) {
                $value .= ' …';
            }
            if (str_contains($label, 'meta date') || str_starts_with($value, '[same as')) {
                continue;
            }
            $note = '';
            if (preg_match('/^b\d+,/', $label)) {
                $note = 'محتوى محتمل مخفي في وحدات البت';
                $interesting++;
            } elseif (str_contains($label, 'extradata')) {
                $note = 'يوجد بيانات إضافية ملحقة بالصورة';
                $interesting++;
            } elseif (str_contains($label, 'text') || str_contains($label, 'file') || str_contains($label, 'comment')) {
                $note = 'بيانات نصية/ملف داخل بنية الصورة';
                $interesting++;
            }
            $findings[] = ['label' => $label, 'value' => mb_substr($value, 0, 400), 'note' => $note];
            if (count($findings) >= 200) {
                break;
            }
        }

        $note = $res['timed_out'] ? 'انتهت المهلة قبل إكمال الفحص' : ($interesting . ' مؤشر');

        return [
            'available' => true,
            'note' => $note,
            'findings' => array_slice($findings, 0, 120),
            'count' => $interesting,
            'raw' => $res['stdout'] . ($res['stderr'] !== '' ? "\n--- stderr ---\n" . $res['stderr'] : ''),
        ];
    }

    private function runSteghide(): array
    {
        $bin = $this->bin('steghide');
        if (!$bin) {
            return ['available' => false, 'note' => 'steghide غير مثبت', 'embedded' => false, 'raw' => ''];
        }
        $supported = ['image/jpeg', 'image/png', 'image/bmp', 'image/x-ms-bmp'];
        if (!in_array($this->mime, $supported, true)) {
            return ['available' => true, 'note' => 'يدعم steghide صور JPEG/BMP', 'embedded' => false, 'raw' => ''];
        }

        $res = Runner::run([$bin, 'info', $this->file, '-p', ''], $this->timeout(), 65536, 32768, "y\n");
        $out = $res['stdout'] . $res['stderr'];
        $embedded = str_contains($out, 'embedded file') || str_contains($out, 'embedded data');
        $negative = str_contains($out, 'could not extract') || str_contains($out, 'no embedded');

        return [
            'available' => true,
            'embedded' => $embedded && !$negative,
            'note' => $embedded && !$negative
                ? 'تم اكتشاف بيانات مضمّنة بواسطة steghide'
                : 'لا توجد بيانات مضمّنة عبر steghide',
            'raw' => $out,
        ];
    }
}
