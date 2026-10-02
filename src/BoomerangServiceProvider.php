<?php

namespace BeeInteractive\Boomerang;

use BeeInteractive\Boomerang\Console\TestCommand;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Throwable;

class BoomerangServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/boomerang.php', 'boomerang');

        $this->app->singleton(Reporter::class);
        $this->app->singleton(Context::class);
        $this->app->singleton(Stacktrace::class);
        $this->app->singleton(Throttle::class);
    }

    public function boot(Dispatcher $events): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) {
            if ($handler instanceof Handler) {
                $handler->reportable(fn (Throwable $exception) => $this->app->make(Reporter::class)->report($exception));
            }
        });

        $events->listen(CommandStarting::class, [Context::class, 'commandStarting']);
        $events->listen(CommandFinished::class, [Context::class, 'commandFinished']);
        $events->listen(JobProcessing::class, [Context::class, 'jobProcessing']);
        $events->listen(JobProcessed::class, [Context::class, 'jobProcessed']);

        $this->app->terminating(function () {
            if ($this->app->resolved(Reporter::class)) {
                $this->app->make(Reporter::class)->flush();
            }
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/boomerang.php' => $this->app->configPath('boomerang.php'),
            ], 'boomerang-config');

            $this->commands([TestCommand::class]);

            AboutCommand::add('Boomerang', fn () => [
                'Version' => Boomerang::version(),
                'Enabled' => $this->app->make(Reporter::class)->enabled() ? '<fg=green;options=bold>YES</>' : '<fg=yellow;options=bold>NO</>',
                'Endpoint' => $this->app->make('config')->get('boomerang.endpoint') ?? '<fg=yellow;options=bold>NOT SET</>',
            ]);
        }
    }
}
