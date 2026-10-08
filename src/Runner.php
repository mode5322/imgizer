<?php

namespace App;

/**
 * Runs system commands safely (no shell) with a timeout and output cap.
 */
final class Runner
{
    private const SYSTEM_DIRS = [
        '/usr/local/sbin', '/usr/local/bin', '/usr/sbin',
        '/usr/bin', '/sbin', '/bin',
    ];

    /** @var array<string,string|null> */
    private static array $whichCache = [];

    public static function toolDirs(): array
    {
        $dirs = self::SYSTEM_DIRS;
        $home = getenv('HOME');
        if ($home) {
            foreach (glob($home . '/.local/share/gem/ruby/*/bin') ?: [] as $d) {
                $dirs[] = $d;
            }
            foreach (glob($home . '/.gem/ruby/*/bin') ?: [] as $d) {
                $dirs[] = $d;
            }
        }
        return array_values(array_unique($dirs));
    }

    public static function which(string $bin, string $custom = ''): ?string
    {
        if ($custom !== '' && is_executable($custom)) {
            return $custom;
        }
        $cacheKey = $bin;
        if (array_key_exists($cacheKey, self::$whichCache)) {
            return self::$whichCache[$cacheKey];
        }
        $found = null;
        if (str_contains($bin, '/')) {
            if (is_executable($bin)) {
                $found = $bin;
            }
            return self::$whichCache[$cacheKey] = $found;
        }
        foreach (self::toolDirs() as $dir) {
            $path = $dir . '/' . $bin;
            if (is_executable($path)) {
                $found = $path;
                break;
            }
        }
        return self::$whichCache[$cacheKey] = $found;
    }

    /**
     * @param string[] $cmd
     * @return array{ok:bool,code:int,stdout:string,stderr:string,duration_ms:int,timed_out:bool,error:string}
     */
    public static function run(array $cmd, int $timeout = 30, int $maxBytes = 2097152, int $maxStderr = 65536, ?string $stdin = null): array
    {
        $result = [
            'ok' => false, 'code' => -1, 'stdout' => '', 'stderr' => '',
            'duration_ms' => 0, 'timed_out' => false, 'error' => '',
        ];
        if (!$cmd) {
            $result['error'] = 'empty command';
            return $result;
        }

        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $env['PATH'] = implode(':', self::toolDirs());
        $env['LANG'] = 'C.UTF-8';
        $env['LC_ALL'] = 'C.UTF-8';

        $descriptors = [
            0 => $stdin === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $start = microtime(true);
        $pipes = [];
        $proc = @proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!is_resource($proc)) {
            $result['error'] = 'failed to start process: ' . ($cmd[0] ?? '?');
            return $result;
        }

        if ($stdin !== null) {
            @fwrite($pipes[0], $stdin);
            @fclose($pipes[0]);
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $exitCode = -1;

        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
            }

            $read = [];
            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }
            if ($read) {
                $w = null;
                $e = null;
                if (@stream_select($read, $w, $e, 0, 200000) > 0) {
                    foreach ($read as $stream) {
                        $chunk = fread($stream, 65536);
                        if ($chunk === false || $chunk === '') {
                            continue;
                        }
                        if ($stream === $pipes[1]) {
                            if (strlen($stdout) < $maxBytes) {
                                $stdout .= substr($chunk, 0, $maxBytes - strlen($stdout));
                            }
                        } elseif (strlen($stderr) < $maxStderr) {
                            $stderr .= substr($chunk, 0, $maxStderr - strlen($stderr));
                        }
                    }
                }
            }

            if (!$status['running']) {
                break;
            }
            if ((microtime(true) - $start) > $timeout) {
                $timedOut = true;
                proc_terminate($proc, 9);
                usleep(150000);
                break;
            }
        }

        foreach ([1, 2] as $i) {
            if (!feof($pipes[$i])) {
                $rest = stream_get_contents($pipes[$i]);
                if ($rest !== false && $rest !== '') {
                    if ($i === 1 && strlen($stdout) < $maxBytes) {
                        $stdout .= substr($rest, 0, $maxBytes - strlen($stdout));
                    } elseif ($i === 2 && strlen($stderr) < $maxStderr) {
                        $stderr .= substr($rest, 0, $maxStderr - strlen($stderr));
                    }
                }
            }
            fclose($pipes[$i]);
        }

        $closeCode = proc_close($proc);
        if ($exitCode === -1 && $closeCode >= 0) {
            $exitCode = $closeCode;
        }

        $result['code'] = $timedOut ? -1 : $exitCode;
        $result['stdout'] = $stdout;
        $result['stderr'] = $stderr;
        $result['duration_ms'] = (int) round((microtime(true) - $start) * 1000);
        $result['timed_out'] = $timedOut;
        $result['ok'] = !$timedOut && $exitCode === 0;
        return $result;
    }
}
