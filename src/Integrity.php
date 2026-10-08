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

        // 1) File structure notes (appended data, CRC errors...)
        foreach (($this->structure['notes'] ?? []) as $note) {
            $findings[] = ['level' => $note['level'] ?? 'info', 'title' => 'File structure', 'detail' => $note['text'] ?? ''];
        }

        // 2) EXIF presence
        if (empty($this->highlights['has_exif'])) {
            $findings[] = ['level' => 'warn', 'title' => 'EXIF metadata', 'detail' => 'No EXIF/IPTC/XMP metadata found; it may have been deliberately stripped (to hide source or location).'];
        } else {
            $findings[] = ['level' => 'ok', 'title' => 'EXIF metadata', 'detail' => 'Image contains readable metadata.'];
        }

        // 3) Geolocation
        if (!empty($this->highlights['gps']['available'])) {
            $findings[] = ['level' => 'warn', 'title' => 'Location privacy', 'detail' => 'Image contains GPS coordinates that reveal where it was taken.'];
        }

        // 4) Editing software and XMP history
        if (!empty($this->highlights['editing'])) {
            foreach ($this->highlights['editing'] as $e) {
                $findings[] = ['level' => 'warn', 'title' => 'Edit indicator', 'detail' => $e['label'] . ': ' . $e['value']];
            }
        }

        // 5) Date comparison
        $dates = [];
        $dateMap = [
            'Date taken (DateTimeOriginal)' => ['DateTimeOriginal'],
            'Date created (CreateDate)' => ['CreateDate'],
            'Metadata modified (ModifyDate)' => ['ModifyDate'],
            'File modified (filesystem)' => ['FileModifyDate'],
            'GPS date' => ['GPSDateTime'],
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
        $original = $byLabel['Date taken (DateTimeOriginal)']['ts'] ?? null;
        $fileMod = $byLabel['File modified (filesystem)']['ts'] ?? null;
        $exifMod = $byLabel['Metadata modified (ModifyDate)']['ts'] ?? null;
        $create = $byLabel['Date created (CreateDate)']['ts'] ?? null;

        if ($original !== null && $fileMod !== null && $fileMod < $original) {
            $findings[] = ['level' => 'danger', 'title' => 'Time inconsistency', 'detail' => 'File mtime predates the capture date — possible metadata tampering.'];
        }
        if ($original !== null && $exifMod !== null && abs($exifMod - $original) > 60) {
            $findings[] = ['level' => 'warn', 'title' => 'Time inconsistency', 'detail' => 'Metadata ModifyDate differs from DateTimeOriginal (possibly edited then re-saved).'];
        }
        if ($original === null && ($create !== null || $exifMod !== null)) {
            $findings[] = ['level' => 'warn', 'title' => 'Missing date', 'detail' => 'The original capture date (DateTimeOriginal) is missing.'];
        }
        if ($original !== null && $fileMod !== null && abs($fileMod - $original) > 365 * 86400) {
            $findings[] = ['level' => 'info', 'title' => 'Large time gap', 'detail' => 'The gap between capture date and file modification exceeds one year.'];
        }

        // 6) Thumbnail
        $thumbOffset = $this->value(['ThumbnailOffset']);
        $thumbLength = $this->value(['ThumbnailLength']);
        $thumbImage = $this->value(['ThumbnailImage']);
        $thumbnail = ['present' => $thumbImage !== null && $thumbImage !== '', 'offset' => $thumbOffset, 'length' => $thumbLength];
        if ($thumbOffset !== null && ($thumbLength === null || (int) $thumbLength === 0) && !$thumbnail['present']) {
            $findings[] = ['level' => 'warn', 'title' => 'Stripped thumbnail', 'detail' => 'ThumbnailOffset present but thumbnail data missing — a common artifact of metadata scrubbing.'];
        }

        // 7) Extension vs actual type mismatch
        $realExt = strtolower((string) $this->value(['FileTypeExtension']));
        $givenExt = strtolower((string) ($this->fileMeta['ext'] ?? ''));
        if ($realExt !== '' && $givenExt !== '' && $realExt !== $givenExt && !($realExt === 'jpeg' && $givenExt === 'jpg')) {
            $findings[] = ['level' => 'warn', 'title' => 'Extension mismatch', 'detail' => "Uploaded extension «{$givenExt}» but the real type is «{$realExt}»."];
        }

        // 8) Comments / UserComment
        $userComment = $this->value(['UserComment', 'Comment']);
        if ($userComment !== null && $userComment !== '') {
            $findings[] = ['level' => 'info', 'title' => 'Comment', 'detail' => mb_substr($userComment, 0, 200)];
        }

        // 9) ExifTool warnings
        $stored = $this->structure['_exiftool_warnings'] ?? [];
        foreach ($stored as $w) {
            $findings[] = ['level' => 'warn', 'title' => 'ExifTool warning', 'detail' => (string) $w];
        }

        $order = ['danger' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];
        usort($findings, static fn ($a, $b) => ($order[$a['level']] ?? 9) <=> ($order[$b['level']] ?? 9));

        $hasDanger = (bool) array_filter($findings, static fn ($f) => $f['level'] === 'danger');
        $hasWarn = (bool) array_filter($findings, static fn ($f) => $f['level'] === 'warn');
        $level = $hasDanger ? 'danger' : ($hasWarn ? 'warn' : 'ok');
        $titles = [
            'danger' => 'Suspicious / tampered file',
            'warn' => 'Needs attention',
            'ok' => 'No signs of tampering',
        ];

        return [
            'verdict' => ['level' => $level, 'title' => $titles[$level], 'count' => count($findings)],
            'findings' => $findings,
            'dates' => array_map(static fn ($d) => ['label' => $d['label'], 'value' => $d['value']], $dates),
            'thumbnail' => $thumbnail,
        ];
    }
}
