<?php

namespace App\Tools;

use App\Runner;

final class ImageMagickTool extends BaseTool
{
    public function id(): string
    {
        return 'imagemagick';
    }

    public function name(): string
    {
        return 'ImageMagick';
    }

    public function icon(): string
    {
        return "\u{1F9EA}";
    }

    protected function run(): array
    {
        $bin = $this->bin('identify') ?? $this->bin('magick');
        if (!$bin) {
            return $this->missing('imagemagick');
        }

        $format = '%m|%w|%h|%z|%[colorspace]|%[type]|%Q|%n|%b|%[interlace]|%[units]|%[channels]';
        $summaryRun = Runner::run([$bin, '-format', $format . "\n", $this->file], $this->timeout(), 65536);
        $summary = [];
        $firstLine = trim(strtok($summaryRun['stdout'], "\n") ?: '');
        if ($firstLine !== '') {
            $parts = explode('|', $firstLine);
            $keys = ['format', 'width', 'height', 'depth', 'colorspace', 'type', 'quality', 'frames', 'filesize', 'interlace', 'units', 'channels'];
            foreach ($keys as $i => $key) {
                $summary[$key] = $parts[$i] ?? '';
            }
        }

        $verboseRun = Runner::run([$bin, '-verbose', $this->file . '[0]'], $this->timeout(), $this->maxRaw());
        $verbose = $verboseRun['stdout'];
        if (trim($verbose) === '' && $summaryRun['stdout'] !== '') {
            $verboseRun = Runner::run([$bin, '-verbose', $this->file], $this->timeout(), $this->maxRaw());
            $verbose = $verboseRun['stdout'];
        }

        $properties = [];
        $capture = false;
        foreach (preg_split('/\r?\n/', $verbose) as $line) {
            if (preg_match('/^Properties:/', $line)) {
                $capture = true;
                continue;
            }
            if ($capture && preg_match('/^\s{2}([A-Za-z][^:]*?):\s+(.+)$/', $line, $m)) {
                $properties[trim($m[1])] = trim($m[2]);
            }
        }

        $highlights = [];
        foreach (['Geometry', 'Colorspace', 'Type', 'Depth', 'Quality', 'Compression', 'Resolution', 'Units', 'Interlace', 'Filesize', 'Colormap', 'Gamma', 'Profiles', 'Rendering intent'] as $key) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . ':\s*(.+)$/mi', $verbose, $m)) {
                $highlights[$key] = trim($m[1]);
            }
        }

        return [
            'status' => 'ok',
            'note' => ($summary['format'] ?? '?') . ' ' . ($summary['width'] ?? '?') . '×' . ($summary['height'] ?? '?'),
            'data' => [
                'summary' => $summary,
                'highlights' => $highlights,
                'properties' => $properties,
                'frames' => (int) ($summary['frames'] ?? 1),
            ],
            'raw' => $verbose,
        ];
    }
}
