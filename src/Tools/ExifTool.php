<?php

namespace App\Tools;

use App\Runner;

final class ExifTool extends BaseTool
{
    public function id(): string
    {
        return 'exiftool';
    }

    public function name(): string
    {
        return 'ExifTool';
    }

    public function icon(): string
    {
        return "\u{1F4F7}";
    }

    protected function run(): array
    {
        $bin = $this->bin('exiftool');
        if (!$bin) {
            return $this->missing('exiftool');
        }

        $res = Runner::run([
            $bin, '-json', '-G1', '-a', '-u', '-c', '%.6f', $this->file,
        ], $this->timeout(), $this->maxRaw() * 2);

        $parsed = json_decode($res['stdout'], true);
        if (!is_array($parsed) || !isset($parsed[0])) {
            return [
                'status' => 'error',
                'note' => trim($res['stderr']) ?: 'Failed to parse exiftool output',
                'data' => null,
                'raw' => $res['stdout'] . "\n" . $res['stderr'],
            ];
        }

        $record = $parsed[0];
        $items = [];
        foreach ($record as $key => $value) {
            if (in_array($key, ['SourceFile', 'ExifTool:ExifToolVersion'], true)) {
                continue;
            }
            $group = 'File';
            $tag = $key;
            if (str_contains($key, ':')) {
                [$group, $tag] = explode(':', $key, 2);
            }
            $items[] = [
                'group' => $group,
                'tag' => $tag,
                'value' => is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE),
            ];
        }

        usort($items, static function ($a, $b) {
            return [$a['group'], $a['tag']] <=> [$b['group'], $b['tag']];
        });

        $warnings = [];
        foreach (preg_split('/\r?\n/', trim($res['stderr'] ?: '')) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $warnings[] = $line;
            }
        }

        return [
            'status' => 'ok',
            'note' => count($items) . ' tags',
            'data' => [
                'items' => $items,
                'warnings' => $warnings,
                'raw_record' => $record,
            ],
            'raw' => $res['stdout'],
        ];
    }
}
