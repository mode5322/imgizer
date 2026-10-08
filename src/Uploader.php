<?php

namespace App;

final class Uploader
{
    private string $storageDir;
    private string $tmpDir;

    public function __construct(string $storageDir, string $tmpDir)
    {
        $this->storageDir = rtrim($storageDir, '/');
        $this->tmpDir = rtrim($tmpDir, '/');
        foreach ([$this->storageDir, $this->tmpDir] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }

    /**
     * @return array{token:string,path:string,ext:string}|null
     */
    public function store(string $tmpPath, string $ext): ?array
    {
        $token = bin2hex(random_bytes(16));
        $safeExt = preg_replace('/[^a-z0-9]/', '', strtolower($ext));
        $dest = $this->storageDir . '/' . $token . '.' . $safeExt;
        if (!@move_uploaded_file($tmpPath, $dest)) {
            if (!@rename($tmpPath, $dest) && !@copy($tmpPath, $dest)) {
                return null;
            }
        }
        @chmod($dest, 0644);
        return ['token' => $token, 'path' => $dest, 'ext' => $safeExt];
    }

    public function writeMeta(string $token, array $meta): void
    {
        @file_put_contents(
            $this->storageDir . '/' . $token . '.json',
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function readMeta(string $token): ?array
    {
        $file = $this->storageDir . '/' . $token . '.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    public function resolvePath(string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        foreach (glob($this->storageDir . '/' . $token . '.*') ?: [] as $file) {
            if (str_ends_with($file, '.json')) {
                continue;
            }
            return is_file($file) ? $file : null;
        }
        return null;
    }

    public function cleanup(int $retentionHours): void
    {
        $cutoff = time() - ($retentionHours * 3600);
        foreach (glob($this->storageDir . '/*') ?: [] as $file) {
            if (!is_file($file) || basename($file) === '.gitkeep') {
                continue;
            }
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            if (is_file($file) && basename($file) !== '.gitkeep' && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    public function tempFile(string $prefix = 'scan'): string
    {
        return $this->tmpDir . '/' . $prefix . '_' . bin2hex(random_bytes(8));
    }
}
