<?php

namespace BeeInteractive\Boomerang;

use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Throwable;

class PayloadFactory
{
    private const MAX_CHAIN = 10;

    private const MAX_MESSAGE = 5000;

    public function __construct(
        private readonly Application $app,
        private readonly Filesystem $files,
        private readonly Stacktrace $stacktrace,
        private readonly Context $context,
    ) {}

    public function fingerprint(Throwable $exception): string
    {
        $frame = collect($this->stacktrace->frames($exception, withCode: false))->firstWhere('in_app', true);

        return sha1(implode('|', $frame === null
            ? [$exception::class, $exception->getFile(), $exception->getLine()]
            : [$exception::class, $frame['file'], $frame['class'], $frame['function']]));
    }

    public function make(Throwable $exception, int $skipped = 0): array
    {
        $exceptions = $this->chain($exception);

        return $this->scrub([
            'notifier' => ['name' => 'boomerang', 'version' => Boomerang::version()],
            'occurred_at' => Date::now()->toIso8601String(),
            'skipped' => $skipped,
            'exceptions' => $exceptions,
            'context' => $this->context->collect(),
            'environment' => $this->environment($exceptions),
        ]);
    }

    private function chain(Throwable $exception): array
    {
        $chain = [];

        for ($current = $exception; $current !== null && count($chain) < self::MAX_CHAIN; $current = $current->getPrevious()) {
            $chain[] = [
                'class' => $current::class,
                'message' => Str::limit($current->getMessage(), self::MAX_MESSAGE),
                'code' => $current->getCode(),
                'frames' => $this->stacktrace->frames($current),
            ];
        }

        return $chain;
    }

    private function environment(array $exceptions): array
    {
        return [
            'name' => $this->app->environment(),
            'revision' => $this->revision(),
            'hostname' => gethostname() ?: null,
            'php' => PHP_VERSION,
            'laravel' => $this->app->version(),
            'packages' => $this->packages($exceptions),
        ];
    }

    private function revision(): ?string
    {
        $path = $this->app->basePath('REVISION');

        return $this->files->isFile($path) ? (trim($this->files->get($path)) ?: null) : null;
    }

    private function packages(array $exceptions): array
    {
        return collect($exceptions)
            ->pluck('frames')
            ->flatten(1)
            ->map(fn (array $frame) => preg_match('#^vendor/([^/]+/[^/]+)/#', (string) $frame['file'], $matches) === 1 ? $matches[1] : null)
            ->filter(fn (?string $package) => $package !== null && InstalledVersions::isInstalled($package))
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $package) => [$package => InstalledVersions::getPrettyVersion($package)])
            ->all();
    }

    private function scrub(array $payload): array
    {
        array_walk_recursive($payload, function (mixed &$value) {
            if (is_string($value)) {
                $value = mb_scrub($value, 'UTF-8');
            }
        });

        return $payload;
    }
}
