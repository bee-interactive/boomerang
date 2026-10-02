<?php

namespace BeeInteractive\Boomerang;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

class Stacktrace
{
    private const MAX_FRAMES = 100;

    private array $views = [];

    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
        private readonly Filesystem $files,
    ) {}

    public function frames(Throwable $exception, bool $withCode = true): array
    {
        $trace = $exception->getTrace();
        $locations = [[$exception->getFile(), $exception->getLine()]];

        foreach ($trace as $call) {
            $locations[] = [$call['file'] ?? null, $call['line'] ?? null];
        }

        $frames = [];

        foreach (array_slice($locations, 0, self::MAX_FRAMES) as $index => [$file, $line]) {
            $frames[] = $this->frame($file, $line, $trace[$index] ?? [], $withCode);
        }

        return $frames;
    }

    private function frame(?string $file, ?int $line, array $call, bool $withCode): array
    {
        $view = $file === null ? null : $this->view($file);
        $source = $view ?? $file;
        $inApp = $source !== null && $this->inApp($source);

        return [
            'file' => $source === null ? null : $this->relative($source),
            'line' => $view === null ? $line : null,
            'compiled' => $view === null ? null : ['file' => $this->relative($file), 'line' => $line],
            'class' => $call['class'] ?? null,
            'function' => $call['function'] ?? null,
            'in_app' => $inApp,
            'code' => $withCode && $inApp && $line !== null ? $this->code($file, $line) : null,
        ];
    }

    private function view(string $file): ?string
    {
        $compiled = $this->config->get('view.compiled');

        if (! is_string($compiled) || ! str_starts_with($file, Str::finish($compiled, DIRECTORY_SEPARATOR))) {
            return null;
        }

        if (! array_key_exists($file, $this->views)) {
            $this->views[$file] = rescue(
                fn () => preg_match('/\/\*\*PATH (.+?) ENDPATH\*\*\//', $this->files->get($file), $matches) === 1 ? $matches[1] : null,
                report: false,
            );
        }

        return $this->views[$file];
    }

    private function inApp(string $path): bool
    {
        return str_starts_with($path, $this->app->basePath().DIRECTORY_SEPARATOR)
            && ! str_starts_with($path, $this->app->basePath('vendor').DIRECTORY_SEPARATOR);
    }

    private function relative(string $path): string
    {
        $base = $this->app->basePath().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function code(string $file, int $line): ?array
    {
        return rescue(function () use ($file, $line) {
            $radius = (int) $this->config->get('boomerang.context_lines', 5);
            $start = max(1, $line - $radius);

            return $this->files->lines($file)
                ->slice($start - 1, $line + $radius - $start + 1)
                ->values()
                ->mapWithKeys(fn (string $code, int $index) => [$start + $index => Str::limit(rtrim($code), 300)])
                ->all();
        }, report: false);
    }
}
