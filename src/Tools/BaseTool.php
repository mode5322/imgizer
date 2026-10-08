<?php

namespace App\Tools;

use App\Runner;

abstract class BaseTool
{
    protected array $config;
    protected string $file;
    protected string $mime;
    /** @var array{0:int,1:int} */
    protected array $dims;

    public function __construct(array $config, string $file, string $mime, array $dims)
    {
        $this->config = $config;
        $this->file = $file;
        $this->mime = $mime;
        $this->dims = $dims;
    }

    abstract public function id(): string;

    abstract public function name(): string;

    public function icon(): string
    {
        return "\u{1F527}";
    }

    /**
     * @return array{status:string,note?:string,data?:mixed,raw?:string}
     */
    abstract protected function run(): array;

    public function analyze(): array
    {
        $start = microtime(true);
        try {
            $result = $this->run();
        } catch (\Throwable $e) {
            $result = ['status' => 'error', 'note' => $e->getMessage(), 'data' => null, 'raw' => ''];
        }
        return array_merge([
            'id' => $this->id(),
            'name' => $this->name(),
            'icon' => $this->icon(),
            'status' => 'ok',
            'note' => '',
            'data' => null,
            'raw' => '',
        ], $result, [
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ]);
    }

    protected function bin(string $name): ?string
    {
        return Runner::which($name, (string) ($this->config['tool_paths'][$name] ?? ''));
    }

    protected function timeout(): int
    {
        return (int) $this->config['command_timeout'];
    }

    protected function maxRaw(): int
    {
        return (int) $this->config['max_raw_bytes'];
    }

    protected function missing(string $tool): array
    {
        return [
            'status' => 'missing',
            'note' => "The tool «{$tool}» is not installed on the server. Run install.sh to install the full package.",
            'data' => null,
            'raw' => '',
        ];
    }

    protected function isRaster(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
