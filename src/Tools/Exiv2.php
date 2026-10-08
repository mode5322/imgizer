<?php

namespace App\Tools;

use App\Runner;

final class Exiv2 extends BaseTool
{
    public function id(): string
    {
        return 'exiv2';
    }

    public function name(): string
    {
        return 'Exiv2';
    }

    public function icon(): string
    {
        return "\u{1F5BC}";
    }

    protected function run(): array
    {
        $bin = $this->bin('exiv2');
        if (!$bin) {
            return $this->missing('exiv2');
        }

        $res = Runner::run([$bin, '-pa', $this->file], $this->timeout(), $this->maxRaw());

        $items = [];
        foreach (preg_split('/\r?\n/', $res['stdout']) as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(\S+)\s+(\S+)\s+(\d+)\s+(.*)$/', $line, $m)) {
                $items[] = [
                    'key' => $m[1],
                    'type' => $m[2],
                    'count' => (int) $m[3],
                    'value' => $m[4],
                ];
            } else {
                $items[] = ['key' => '', 'type' => '', 'count' => 0, 'value' => $line];
            }
        }

        if (!$items && trim($res['stderr']) !== '') {
            return [
                'status' => 'error',
                'note' => trim($res['stderr']),
                'data' => ['items' => []],
                'raw' => $res['stdout'] . "\n" . $res['stderr'],
            ];
        }

        return [
            'status' => 'ok',
            'note' => count($items) . ' حقل',
            'data' => ['items' => $items],
            'raw' => $res['stdout'],
        ];
    }
}
