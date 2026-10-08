<?php

namespace App;

final class Structure
{
    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    /**
     * @return array<string,mixed>
     */
    public function analyze(): array
    {
        $size = (int) (filesize($this->file) ?: 0);
        $limit = 64 * 1024 * 1024;
        $handle = @fopen($this->file, 'rb');
        if (!$handle) {
            return ['format' => 'unknown', 'error' => 'Unable to open file'];
        }
        $data = $size > $limit ? fread($handle, $limit) : stream_get_contents($handle);
        fclose($handle);
        if ($data === false) {
            return ['format' => 'unknown', 'error' => 'Unable to read file'];
        }

        if (str_starts_with($data, "\xFF\xD8")) {
            return $this->parseJpeg($data, $size);
        }
        if (str_starts_with($data, "\x89PNG\r\n\x1A\n")) {
            return $this->parsePng($data, $size);
        }
        if (str_starts_with($data, 'GIF87a') || str_starts_with($data, 'GIF89a')) {
            return $this->parseGif($data, $size);
        }

        return ['format' => 'other', 'notes' => [], 'comments' => [], 'trailing_bytes' => 0];
    }

    private static function be16(string $d, int $o): int
    {
        return (ord($d[$o]) << 8) | ord($d[$o + 1]);
    }

    private static function be32(string $d, int $o): int
    {
        return (ord($d[$o]) << 24) | (ord($d[$o + 1]) << 16) | (ord($d[$o + 2]) << 8) | ord($d[$o + 3]);
    }

    private function parseJpeg(string $data, int $size): array
    {
        $segments = [];
        $comments = [];
        $notes = [];
        $len = strlen($data);
        $pos = 2;
        $eoi = null;
        $sawExif = false;
        $sawJfif = false;

        while ($pos + 1 < $len) {
            if (ord($data[$pos]) !== 0xFF) {
                $pos++;
                continue;
            }
            $marker = ord($data[$pos + 1]);
            if ($marker === 0xFF) {
                $pos++;
                continue;
            }
            if ($marker === 0xD8) {
                $pos += 2;
                continue;
            }
            if ($marker === 0xD9) {
                $pos += 2;
                $eoi = $pos;
                break;
            }
            if ($marker === 0xDA) {
                $sosLen = $pos + 2 <= $len - 2 ? self::be16($data, $pos + 2) : 0;
                $segments[] = ['offset' => $pos, 'marker' => 'SOS', 'name' => 'Start of Scan', 'size' => $sosLen, 'info' => 'Start of compressed image data'];
                $eoi = $this->findEoi($data, $pos + 2 + $sosLen);
                break;
            }
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                $pos += 2;
                continue;
            }
            if ($pos + 4 > $len) {
                break;
            }
            $segLen = self::be16($data, $pos + 2);
            $payload = substr($data, $pos + 4, max(0, $segLen - 2));
            $name = $this->jpegMarkerName($marker);
            $info = '';

            if ($marker === 0xE0 && str_starts_with($payload, "JFIF\0")) {
                $sawJfif = true;
                $info = 'JFIF v' . ord($payload[5]) . '.' . str_pad((string) ord($payload[6]), 2, '0', STR_PAD_LEFT);
            } elseif ($marker === 0xE1 && str_starts_with($payload, "Exif\0\0")) {
                $sawExif = true;
                $info = 'EXIF data';
            } elseif ($marker === 0xE1 && str_starts_with($payload, 'http://ns.adobe.com/xap/1.0/')) {
                $info = 'XMP data';
            } elseif ($marker === 0xE2 && str_starts_with($payload, "ICC_PROFILE\0")) {
                $info = 'ICC profile';
            } elseif ($marker === 0xED && str_starts_with($payload, 'Photoshop 3.0')) {
                $info = 'Photoshop resource (IPTC/IRB)';
            } elseif ($marker === 0xEE && str_starts_with($payload, 'Adobe')) {
                $info = 'Adobe APP14';
            } elseif ($marker === 0xFE) {
                $clean = preg_replace('/[^\x20-\x7E\x0A\x0D\x{0600}-\x{06FF}]+/u', ' ', $payload) ?? $payload;
                $clean = trim((string) $clean);
                if ($clean !== '') {
                    $comments[] = substr($clean, 0, 500);
                }
                $info = 'Comment (COM)';
            }

            $segments[] = ['offset' => $pos, 'marker' => strtoupper(dechex($marker)), 'name' => $name, 'size' => $segLen, 'info' => $info];
            $pos += 2 + $segLen;
            if (count($segments) > 200) {
                break;
            }
        }

        if ($eoi === null) {
            $notes[] = ['level' => 'warn', 'text' => 'EOF marker (FFD9) was not found.'];
            $eoi = $len;
        }
        $trailing = max(0, $size - $eoi);
        if ($trailing > 0) {
            $notes[] = ['level' => 'danger', 'text' => "{$trailing} bytes appended after the end of image (possible hidden data)."];
        }
        if (!$sawExif && !$sawJfif) {
            $notes[] = ['level' => 'info', 'text' => 'Image has no standard JFIF/EXIF marker.'];
        }

        return [
            'format' => 'JPEG',
            'segments' => $segments,
            'comments' => $comments,
            'trailing_bytes' => $trailing,
            'notes' => $notes,
            'data_size' => $size,
        ];
    }

    private function findEoi(string $data, int $start): int
    {
        $len = strlen($data);
        for ($i = $start; $i + 1 < $len; $i++) {
            if ($data[$i] === "\xFF") {
                $next = ord($data[$i + 1]);
                if ($next !== 0x00 && !($next >= 0xD0 && $next <= 0xD7)) {
                    if ($next === 0xD9) {
                        return $i + 2;
                    }
                }
            }
        }
        return $len;
    }

    private function jpegMarkerName(int $marker): string
    {
        $names = [
            0xC0 => 'SOF0', 0xC1 => 'SOF1', 0xC2 => 'SOF2', 0xC3 => 'SOF3',
            0xC4 => 'DHT', 0xC5 => 'SOF5', 0xC6 => 'SOF6', 0xC7 => 'SOF7',
            0xC8 => 'JPG', 0xC9 => 'SOF9', 0xCA => 'SOF10', 0xCB => 'SOF11',
            0xCC => 'DAC', 0xCD => 'SOF13', 0xCE => 'SOF14', 0xCF => 'SOF15',
            0xDB => 'DQT', 0xDD => 'DRI', 0xFE => 'COM',
        ];
        if ($marker >= 0xE0 && $marker <= 0xEF) {
            return 'APP' . ($marker - 0xE0);
        }
        if ($marker >= 0xC0 && $marker <= 0xCF) {
            return $names[$marker] ?? 'SOF' . ($marker - 0xC0);
        }
        return $names[$marker] ?? strtoupper(dechex($marker));
    }

    private function parsePng(string $data, int $size): array
    {
        $chunks = [];
        $comments = [];
        $notes = [];
        $crcErrors = [];
        $len = strlen($data);
        $pos = 8;
        $end = $len;
        $idatCount = 0;

        while ($pos + 8 <= $len) {
            $chunkLen = self::be32($data, $pos);
            $type = substr($data, $pos + 4, 4);
            if ($chunkLen < 0 || $pos + 12 + $chunkLen > $len) {
                $notes[] = ['level' => 'warn', 'text' => 'Malformed PNG structure (invalid chunk length).'];
                break;
            }
            $chunkData = substr($data, $pos + 8, $chunkLen);
            $crc = self::be32($data, $pos + 8 + $chunkLen);
            $calc = crc32($type . $chunkData);
            if (($calc & 0xFFFFFFFF) !== ($crc & 0xFFFFFFFF)) {
                $crcErrors[] = $type;
            }

            $info = '';
            if ($type === 'tEXt' || $type === 'zTXt' || $type === 'iTXt') {
                $keyword = strstr($chunkData, "\0", true);
                $info = 'Text data: ' . ($keyword !== false ? $keyword : '');
                if ($keyword !== false) {
                    $comments[] = trim(($keyword !== false ? $keyword . ': ' : '') . mb_substr(preg_replace('/[^\x20-\x7E\x{0600}-\x{06FF}]+/u', ' ', substr($chunkData, strpos($chunkData, "\0") + 1)) ?: '', 0, 300));
                }
            } elseif ($type === 'eXIf') {
                $info = 'EXIF data';
            } elseif ($type === 'pHYs') {
                $info = 'Physical dimensions (DPI)';
            } elseif ($type === 'tIME') {
                $info = 'Embedded modification time';
            } elseif ($type === 'acTL') {
                $info = 'Animated PNG (APNG)';
            } elseif ($type === 'IDAT') {
                $idatCount++;
            }

            $chunks[] = ['type' => $type, 'size' => $chunkLen, 'offset' => $pos, 'info' => $info, 'crc_ok' => !in_array($type, $crcErrors, true)];
            if ($type === 'IDAT') {
                $chunks[array_key_last($chunks)]['info'] = 'Image data #' . $idatCount;
            }

            $pos += 12 + $chunkLen;
            if ($type === 'IEND') {
                $end = $pos;
                break;
            }
            if (count($chunks) > 2000) {
                break;
            }
        }

        if ($crcErrors) {
            $notes[] = ['level' => 'danger', 'text' => 'CRC errors in chunks: ' . implode(', ', array_unique($crcErrors)) . ' (tampering or corruption).'];
        }
        $trailing = max(0, $size - $end);
        if ($trailing > 0) {
            $notes[] = ['level' => 'danger', 'text' => "{$trailing} bytes appended after PNG end (IEND)."];
        }

        return [
            'format' => 'PNG',
            'chunks' => $chunks,
            'comments' => array_slice($comments, 0, 50),
            'crc_errors' => array_values(array_unique($crcErrors)),
            'trailing_bytes' => $trailing,
            'notes' => $notes,
            'data_size' => $size,
        ];
    }

    private function parseGif(string $data, int $size): array
    {
        $comments = [];
        $len = strlen($data);
        $pos = 13;
        if (str_starts_with($data, 'GIF89a') && $len > 13) {
            $flags = ord($data[10]);
            if ($flags & 0x80) {
                $pos += 3 * (2 ** (($flags & 7) + 1));
            }
        }
        while ($pos < $len) {
            $block = ord($data[$pos]);
            $pos++;
            if ($block === 0x3B) {
                break;
            }
            if ($block === 0x21) {
                $label = $len > $pos ? ord($data[$pos]) : 0;
                $pos++;
                if ($label === 0xFE) {
                    $text = '';
                    while ($pos < $len) {
                        $subLen = ord($data[$pos]);
                        $pos++;
                        if ($subLen === 0) {
                            break;
                        }
                        $text .= substr($data, $pos, $subLen);
                        $pos += $subLen;
                    }
                    $clean = trim(preg_replace('/[^\x20-\x7E\s]+/', ' ', $text) ?? '');
                    if ($clean !== '') {
                        $comments[] = mb_substr($clean, 0, 300);
                    }
                } else {
                    while ($pos < $len) {
                        $subLen = ord($data[$pos]);
                        $pos += 1 + $subLen;
                        if ($subLen === 0) {
                            break;
                        }
                    }
                }
            } elseif ($block === 0x2C) {
                $pos += 9;
                if ($pos <= $len && (ord($data[$pos - 1]) & 0x80)) {
                    $pos += 3 * (2 ** ((ord($data[$pos - 1]) & 7) + 1));
                }
                $pos++;
                while ($pos < $len) {
                    $subLen = ord($data[$pos]);
                    $pos += 1 + $subLen;
                    if ($subLen === 0) {
                        break;
                    }
                }
            } else {
                break;
            }
        }

        return [
            'format' => 'GIF',
            'comments' => $comments,
            'trailing_bytes' => max(0, $size - $pos),
            'notes' => [],
            'data_size' => $size,
        ];
    }
}
