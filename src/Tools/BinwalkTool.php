<?php

namespace App\Tools;

use App\Runner;

final class BinwalkTool extends BaseTool
{
    public function id(): string
    {
        return 'binwalk';
    }

    public function name(): string
    {
        return 'Binwalk';
    }

    public function icon(): string
    {
        return "\u{1F50D}";
    }

    protected function run(): array
    {
        $bin = $this->bin('binwalk');
        if (!$bin) {
            return $this->missing('binwalk');
        }

        $res = Runner::run([$bin, $this->file], $this->timeout(), $this->maxRaw());

        $entries = [];
        foreach (preg_split('/\r?\n/', $res['stdout']) as $line) {
            if (preg_match('/^\s*(\d+)\s+0x([0-9A-Fa-f]+)\s+(.+?)\s*$/', $line, $m)) {
                $entries[] = [
                    'offset' => (int) $m[1],
                    'offset_hex' => '0x' . strtoupper($m[2]),
                    'description' => $m[3],
                ];
            }
        }

        $note = count($entries) . ' signature(s) found';
        if (str_contains($res['stdout'], 'Entropy Exception') || str_contains($res['stderr'], 'Entropy Exception')) {
            $note .= ' (note: binwalk entropy module is broken here, using the built-in engine)';
        }

        return [
            'status' => 'ok',
            'note' => $note,
            'data' => ['entries' => $entries],
            'raw' => $res['stdout'] . ($res['stderr'] !== '' ? "\n--- stderr ---\n" . $res['stderr'] : ''),
        ];
    }
}
