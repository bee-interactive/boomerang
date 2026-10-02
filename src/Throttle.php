<?php

namespace BeeInteractive\Boomerang;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Throwable;

class Throttle
{
    private ?Cache $store = null;

    public function __construct(
        private readonly CacheManager $cache,
        private readonly Repository $config,
    ) {}

    public function attempt(string $fingerprint): ?int
    {
        try {
            $store = $this->store();

            if (! $store->add("boomerang:{$fingerprint}", true, (int) $this->config->get('boomerang.throttle', 10))) {
                $store->increment("boomerang:{$fingerprint}:skipped");

                return null;
            }

            return (int) $store->pull("boomerang:{$fingerprint}:skipped", 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function store(): Cache
    {
        return $this->store ??= $this->cache->build([
            'driver' => 'file',
            'path' => $this->config->get('boomerang.storage_path').'/throttle',
        ]);
    }
}
