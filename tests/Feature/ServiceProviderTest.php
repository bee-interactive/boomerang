<?php

use BeeInteractive\Boomerang\BoomerangServiceProvider;
use BeeInteractive\Boomerang\Reporter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

it('merges its configuration', function () {
    expect(config('boomerang'))
        ->toHaveKeys(['endpoint', 'token', 'timeout', 'connect_timeout', 'throttle', 'spool', 'context_lines', 'redact', 'storage_path'])
        ->timeout->toBe(2.0)
        ->connect_timeout->toBe(1.0)
        ->throttle->toBe(10);
});

it('publishes its configuration under the boomerang-config tag', function () {
    $paths = ServiceProvider::pathsToPublish(BoomerangServiceProvider::class, 'boomerang-config');

    expect(array_values($paths))->toBe([config_path('boomerang.php')])
        ->and(realpath(array_key_first($paths)))->toBe(realpath(__DIR__.'/../../config/boomerang.php'));
});

it('leaves exception handlers it does not know alone', function () {
    $this->app->singleton(ExceptionHandler::class, fn () => new class() implements ExceptionHandler
    {
        public function report(Throwable $e) {}

        public function shouldReport(Throwable $e)
        {
            return true;
        }

        public function render($request, Throwable $e) {}

        public function renderForConsole($output, Throwable $e) {}
    });

    expect(app(ExceptionHandler::class))->toBeInstanceOf(ExceptionHandler::class);
});

it('does not build the reporter just to terminate', function () {
    $this->app->forgetInstance(Reporter::class);

    $this->app->terminate();

    expect($this->app->resolved(Reporter::class))->toBeFalse();
});

it('tells about itself', function () {
    $this->artisan('about', ['--only' => 'boomerang'])
        ->expectsOutputToContain('Boomerang')
        ->expectsOutputToContain('YES')
        ->expectsOutputToContain('https://interactive.test/api/boomerang/albishorn')
        ->assertSuccessful();
});

it('tells when it is not configured', function () {
    config(['boomerang.endpoint' => null]);

    $this->artisan('about', ['--only' => 'boomerang'])
        ->expectsOutputToContain('NOT SET')
        ->doesntExpectOutputToContain('YES')
        ->assertSuccessful();
});

it('reports through a handler that wraps the application one, like Collision does in the console', function () {
    Http::fake(['*' => Http::response(status: 202)]);

    $this->app->instance(ExceptionHandler::class, new class(new Handler($this->app)) implements ExceptionHandler
    {
        public function __construct(private ExceptionHandler $handler) {}

        public function report(Throwable $e)
        {
            $this->handler->report($e);
        }

        public function shouldReport(Throwable $e)
        {
            return $this->handler->shouldReport($e);
        }

        public function render($request, Throwable $e)
        {
            return $this->handler->render($request, $e);
        }

        public function renderForConsole($output, Throwable $e)
        {
            $this->handler->renderForConsole($output, $e);
        }

        public function reportable(callable $reportUsing)
        {
            return $this->handler->reportable($reportUsing);
        }
    });

    (new BoomerangServiceProvider($this->app))->boot($this->app['events']);

    app(ExceptionHandler::class)->report(new RuntimeException('Command "boomerang:testes" is not defined.'));

    expect(sentPayloads())->toHaveCount(1);
});
