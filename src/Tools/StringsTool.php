<?php

namespace App\Tools;

use App\Runner;

final class StringsTool extends BaseTool
{
    public function id(): string
    {
        return 'strings';
    }

    public function name(): string
    {
        return 'Strings';
    }

    public function icon(): string
    {
        return "\u{1F524}";
    }

    protected function run(): array
    {
        $bin = $this->bin('strings');
        if (!$bin) {
            return $this->missing('strings');
        }

        $res = Runner::run([$bin, '-a', '-t', 'd', '-n', '5', $this->file], $this->timeout(), $this->maxRaw());
        $lines = preg_split('/\r?\n/', $res['stdout']) ?: [];

        $items = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $offset = 0;
            $text = $line;
            if (preg_match('/^\s*(\d+)\s+(.*)$/', $line, $m)) {
                $offset = (int) $m[1];
                $text = $m[2];
            }
            $items[] = ['offset' => $offset, 'text' => $text];
        }

        $urls = [];
        $emails = [];
        $keywords = [
            'photoshop', 'adobe', 'gimp', 'lightroom', 'capture one', 'paint.net',
            'snapseed', 'picsart', 'canva', 'instagram', 'whatsapp', 'telegram',
            'facebook', 'twitter', 'xmp', 'xmlns', 'icc_profile', 'exif', 'copyright',
            'http://', 'https://', 'secret', 'password', 'flag{', 'key=', 'token',
        ];
        $interesting = [];
        $seen = [];

        foreach ($items as $item) {
            $text = $item['text'];
            if (preg_match_all('~https?://[^\s"\'<>()]{3,}~i', $text, $m)) {
                foreach ($m[0] as $url) {
                    $url = rtrim($url, '.,;');
                    if (!isset($seen['u' . $url])) {
                        $seen['u' . $url] = true;
                        $urls[] = ['offset' => $item['offset'], 'text' => $url];
                    }
                }
            }
            if (preg_match_all('~[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}~', $text, $m)) {
                foreach ($m[0] as $email) {
                    if (!isset($seen['e' . $email])) {
                        $seen['e' . $email] = true;
                        $emails[] = ['offset' => $item['offset'], 'text' => $email];
                    }
                }
            }
            $lower = strtolower($text);
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    $key = 'i' . $item['offset'] . md5($text);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $interesting[] = ['offset' => $item['offset'], 'text' => $text, 'match' => $kw];
                    }
                    break;
                }
            }
            if (count($interesting) >= 200 && count($urls) >= 100 && count($emails) >= 100) {
                break;
            }
        }

        return [
            'status' => 'ok',
            'note' => count($items) . ' نص | ' . count($urls) . ' رابط | ' . count($emails) . ' بريد',
            'data' => [
                'total' => count($items),
                'urls' => array_slice($urls, 0, 100),
                'emails' => array_slice($emails, 0, 100),
                'interesting' => array_slice($interesting, 0, 150),
                'lines' => array_slice($items, 0, 800),
            ],
            'raw' => $res['stdout'],
        ];
    }
}
