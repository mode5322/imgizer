<?php

namespace App;

use App\Tools\BinwalkTool;
use App\Tools\ExifTool;
use App\Tools\Exiv2;
use App\Tools\ImageMagickTool;
use App\Tools\StegoTool;
use App\Tools\StringsTool;

final class Analyzer
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    private static function tag(array $record, array $tags): ?string
    {
        foreach ($tags as $tag) {
            foreach ($record as $key => $value) {
                $pos = strrpos($key, ':');
                $name = preg_replace('/_\d+$/', '', $pos === false ? $key : substr($key, $pos + 1));
                if (strcasecmp($name, $tag) === 0 && $value !== '' && $value !== null) {
                    return is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
                }
            }
        }
        return null;
    }

    public function analyze(string $path, array $meta): array
    {
        $start = microtime(true);

        $mime = $meta['mime'] ?? 'application/octet-stream';
        $dims = [(int) ($meta['width'] ?? 0), (int) ($meta['height'] ?? 0)];

        $toolInstances = [
            new ExifTool($this->config, $path, $mime, $dims),
            new ImageMagickTool($this->config, $path, $mime, $dims),
            new Exiv2($this->config, $path, $mime, $dims),
            new BinwalkTool($this->config, $path, $mime, $dims),
            new StringsTool($this->config, $path, $mime, $dims),
            new StegoTool($this->config, $path, $mime, $dims),
        ];

        $tools = [];
        $byId = [];
        foreach ($toolInstances as $tool) {
            $result = $tool->analyze();
            $tools[] = $result;
            $byId[$result['id']] = $result;
        }

        $record = $byId['exiftool']['data']['raw_record'] ?? [];
        if (($dims[0] === 0 || $dims[1] === 0) && $record) {
            $dims[0] = (int) (self::tag($record, ['ImageWidth', 'ExifImageWidth']) ?? 0);
            $dims[1] = (int) (self::tag($record, ['ImageHeight', 'ExifImageHeight']) ?? 0);
        }
        $mime = self::tag($record, ['MIMEType']) ?? $mime;

        $structure = (new Structure($path))->analyze();
        $structure['_exiftool_warnings'] = $byId['exiftool']['data']['warnings'] ?? [];

        $highlights = Highlights::build($record, $dims);
        $integrity = (new Integrity($record, $structure, $highlights, $meta))->analyze();

        $entropyEngine = new Entropy($path, $mime, $dims, $this->config);
        $fileEntropy = $entropyEngine->fileEntropy();
        $lsb = $entropyEngine->lsb();

        $size = (int) (filesize($path) ?: 0);
        $fileInfo = [
            'token' => $meta['token'] ?? '',
            'name' => $meta['name'] ?? basename($path),
            'size' => $size,
            'size_human' => Security::humanBytes($size),
            'mime' => $mime,
            'ext' => strtolower((string) ($meta['ext'] ?? pathinfo($path, PATHINFO_EXTENSION))),
            'width' => $dims[0],
            'height' => $dims[1],
            'preview_url' => 'preview.php?token=' . urlencode((string) ($meta['token'] ?? '')),
            'hashes' => [
                'md5' => hash_file('md5', $path) ?: '',
                'sha1' => hash_file('sha1', $path) ?: '',
                'sha256' => hash_file('sha256', $path) ?: '',
            ],
        ];

        $cleanStructure = $structure;
        unset($cleanStructure['_exiftool_warnings']);

        return [
            'ok' => true,
            'file' => $fileInfo,
            'highlights' => $highlights,
            'integrity' => $integrity,
            'structure' => $cleanStructure,
            'entropy' => $fileEntropy,
            'lsb' => $lsb,
            'tools' => $tools,
            'meta' => [
                'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
                'generated_at' => date('c'),
                'app' => $this->config['app_name'],
            ],
        ];
    }
}
