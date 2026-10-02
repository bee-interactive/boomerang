<?php

namespace BeeInteractive\Boomerang\Tests;

use BeeInteractive\Boomerang\BoomerangServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $storage;

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->storage);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [BoomerangServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->storage = sys_get_temp_dir().'/boomerang-'.Str::random(8);

        $app['env'] = 'production';

        $app['config']->set('boomerang.endpoint', 'https://interactive.test/api/boomerang/albishorn');
        $app['config']->set('boomerang.token', 'site-token');
        $app['config']->set('boomerang.storage_path', $this->storage);
    }

    protected function runningOverHttp(): void
    {
        (fn () => $this->isRunningInConsole = false)->call($this->app);
    }

    protected function usePackageAsBasePath(): void
    {
        $this->app->setBasePath(dirname(__DIR__));
    }
}
