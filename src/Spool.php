<?php

namespace BeeInteractive\Boomerang;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class Spool
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly Repository $config,
    ) {}

    public function store(array $payload): void
    {
        $directory = $this->directory();

        $this->files->ensureDirectoryExists($directory);

        if (count($this->files->files($directory)) >= (int) $this->config->get('boomerang.spool.limit', 100)) {
            return;
        }

        $this->files->put($directory.'/'.Str::orderedUuid().'.json', json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    public function flush(Transport $transport): void
    {
        if (! $this->files->isDirectory($this->directory())) {
            return;
        }

        $files = array_slice($this->files->files($this->directory()), 0, (int) $this->config->get('boomerang.spool.flush', 10));

        foreach ($files as $file) {
            $payload = json_decode($file->getContents(), true);

            if (is_array($payload) && $transport->send($payload) === Delivery::Retry) {
                return;
            }

            $this->files->delete($file->getPathname());
        }
    }

    private function directory(): string
    {
        return $this->config->get('boomerang.storage_path').'/spool';
    }
}
