<?php

namespace App;

final class Entropy
{
    private string $file;
    private string $mime;
    /** @var array{0:int,1:int} */
    private array $dims;
    private array $config;

    public function __construct(string $file, string $mime, array $dims, array $config)
    {
        $this->file = $file;
        $this->mime = $mime;
        $this->dims = $dims;
        $this->config = $config;
    }

    private static function shannon(string $data): float
    {
        $len = strlen($data);
        if ($len === 0) {
            return 0.0;
        }
        $counts = array_fill(0, 256, 0);
        for ($i = 0; $i < $len; $i++) {
            $counts[ord($data[$i])]++;
        }
        $entropy = 0.0;
        foreach ($counts as $c) {
            if ($c === 0) {
                continue;
            }
            $p = $c / $len;
            $entropy -= $p * log($p, 2);
        }
        return $entropy;
    }

    /**
     * @return array{overall:float,points:array<int,array{offset:int,value:float}>,max:float,min:float,size:int,interpretation:string}
     */
    public function fileEntropy(): array
    {
        $size = (int) (filesize($this->file) ?: 0);
        $handle = @fopen($this->file, 'rb');
        if (!$handle || $size === 0) {
            return ['overall' => 0.0, 'points' => [], 'max' => 0.0, 'min' => 0.0, 'size' => $size, 'interpretation' => 'Unable to read the file.'];
        }

        $window = 512;
        $maxPoints = 256;
        $step = (int) max($window, ceil($size / $maxPoints));
        $points = [];
        $globalCounts = array_fill(0, 256, 0);
        $globalTotal = 0;
        $max = 0.0;
        $min = 8.0;

        for ($offset = 0; $offset < $size; $offset += $step) {
            fseek($handle, $offset);
            $chunk = fread($handle, $window);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $e = self::shannon($chunk);
            $points[] = ['offset' => $offset, 'value' => round($e, 4)];
            $max = max($max, $e);
            $min = min($min, $e);
            $len = strlen($chunk);
            for ($i = 0; $i < $len; $i++) {
                $globalCounts[ord($chunk[$i])]++;
            }
            $globalTotal += $len;
        }
        fclose($handle);

        $overall = 0.0;
        foreach ($globalCounts as $c) {
            if ($c === 0 || $globalTotal === 0) {
                continue;
            }
            $p = $c / $globalTotal;
            $overall -= $p * log($p, 2);
        }

        $interpretation = $overall > 7.9
            ? 'Very high entropy: may indicate compressed/encrypted data or hidden content.'
            : ($overall > 7.0
                ? 'High entropy: expected for compressed images (JPEG/PNG).'
                : 'Low/medium entropy: the image contains repetitive structures.');

        return [
            'overall' => round($overall, 4),
            'points' => $points,
            'max' => round($max, 4),
            'min' => $min > 8 ? 0.0 : round($min, 4),
            'size' => $size,
            'interpretation' => $interpretation,
        ];
    }

    /**
     * Analyses least significant bits (LSB) via ImageMagick pixel extraction.
     *
     * @return array{available:bool,note:string,channels:array<string,array<string,mixed>>,suspicion:int}
     */
    public function lsb(): array
    {
        $unavailable = ['available' => false, 'note' => '', 'channels' => [], 'suspicion' => 0];
        [$w, $h] = $this->dims;
        if ($w < 2 || $h < 2) {
            return array_merge($unavailable, ['note' => 'Image dimensions unknown for bit analysis.']);
        }

        $bin = Runner::which('magick', (string) ($this->config['tool_paths']['magick'] ?? ''))
            ?? Runner::which('convert', (string) ($this->config['tool_paths']['magick'] ?? ''));
        if (!$bin) {
            return array_merge($unavailable, ['note' => 'ImageMagick is required for bit analysis (not installed).']);
        }

        $scale = 400 / max($w, $h);
        $tw = max(2, min(400, (int) round($w * $scale)));
        $th = max(2, min(400, (int) round($h * $scale)));

        $tmp = sys_get_temp_dir() . '/lsb_' . bin2hex(random_bytes(6)) . '.rgb';
        $res = Runner::run([
            $bin, $this->file . '[0]', '-resize', $tw . 'x' . $th . '!',
            '-type', 'TrueColor', '-depth', '8', 'rgb:' . $tmp,
        ], (int) $this->config['command_timeout'], 65536);

        if (!is_file($tmp)) {
            return array_merge($unavailable, ['note' => 'Failed to extract pixels: ' . trim($res['stderr'])]);
        }
        $raw = file_get_contents($tmp);
        @unlink($tmp);
        $expected = $tw * $th * 3;
        if ($raw === false || strlen($raw) < $expected) {
            return array_merge($unavailable, ['note' => 'Pixel data is incomplete.']);
        }
        $raw = substr($raw, 0, $expected);

        $channelNames = ['R', 'G', 'B'];
        $channels = [];
        $suspicion = 0;

        for ($c = 0; $c < 3; $c++) {
            $ones = 0;
            $samples = 0;
            $bits = '';
            $bitPlaneOnes = [0, 0, 0, 0];
            for ($p = 0; $p < $expected; $p += 3) {
                $value = ord($raw[$p + $c]);
                $lsb = $value & 1;
                $ones += $lsb;
                $samples++;
                $bits .= (string) $lsb;
                for ($b = 0; $b < 4; $b++) {
                    $bitPlaneOnes[$b] += ($value >> $b) & 1;
                }
            }

            $printableRuns = $this->extractPrintableRuns($bits);
            $printableRatio = count($printableRuns) / max(1, (int) (strlen($bits) / 8)) * 100;

            $channels[$channelNames[$c]] = [
                'ones_ratio' => $samples ? round($ones / $samples, 5) : 0.0,
                'bit_plane_ones' => array_map(static fn ($v) => $samples ? round($v / $samples, 4) : 0.0, $bitPlaneOnes),
                'printable_runs' => $printableRuns,
                'printable_ratio' => round($printableRatio, 2),
            ];

            if ($printableRuns && strlen($printableRuns[0]['text']) >= 12) {
                $suspicion += 40;
            }
        }

        return [
            'available' => true,
            'note' => 'Heuristic analysis of least significant bits (not conclusive).',
            'channels' => $channels,
            'suspicion' => min(100, $suspicion),
        ];
    }

    /**
     * Packs bits into bytes and looks for printable text runs.
     *
     * @return array<int,array{offset:int,text:string}>
     */
    private function extractPrintableRuns(string $bits): array
    {
        $runs = [];
        $bytes = '';
        $len = strlen($bits);
        for ($i = 0; $i + 8 <= $len; $i += 8) {
            $byte = 0;
            for ($b = 0; $b < 8; $b++) {
                $byte = ($byte << 1) | (int) $bits[$i + $b];
            }
            $bytes .= chr($byte);
        }

        $byteLen = strlen($bytes);
        $i = 0;
        while ($i < $byteLen) {
            $code = ord($bytes[$i]);
            if ($code >= 32 && $code <= 126) {
                $start = $i;
                $text = '';
                while ($i < $byteLen) {
                    $code = ord($bytes[$i]);
                    if ($code < 32 || $code > 126) {
                        break;
                    }
                    $text .= $bytes[$i];
                    $i++;
                }
                if (strlen($text) >= 10 && preg_match('/[A-Za-z]{4,}|https?:\/\/|\{.*\}/', $text)) {
                    $runs[] = ['offset' => $start, 'text' => mb_substr($text, 0, 200)];
                }
            } else {
                $i++;
            }
            if (count($runs) >= 10) {
                break;
            }
        }
        return $runs;
    }
}
