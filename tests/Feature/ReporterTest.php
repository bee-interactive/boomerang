<?php

use BeeInteractive\Boomerang\PayloadFactory;
use BeeInteractive\Boomerang\Reporter;
use BeeInteractive\Boomerang\Tests\Fixtures\Thrower;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

function spoolCount(): int
{
    $directory = config('boomerang.storage_path').'/spool';

    return is_dir($directory) ? count((new Filesystem())->files($directory)) : 0;
}

it('sends what the exception handler reports', function () {
    Http::fake(['*' => Http::response(status: 202)]);
    app(ExceptionHandler::class)->report(new RuntimeException('Something broke'));

    expect(sentPayloads())->toHaveCount(1)
        ->and(sentPayloads()[0]['exceptions'][0]['message'])->toBe('Something broke');
});

it('leaves exceptions the application does not report alone', function () {
    Http::fake(['*' => Http::response(status: 202)]);
    app(ExceptionHandler::class)->dontReport(RuntimeException::class);

    app(ExceptionHandler::class)->report(new RuntimeException('Expected'));

    Http::assertNothingSent();
});

it('stays inert until it is configured', function (string $key) {
    Http::fake(['*' => Http::response(status: 202)]);
    config(["boomerang.{$key}" => null]);

    app(Reporter::class)->report(new RuntimeException('Something broke'));

    expect(app(Reporter::class)->enabled())->toBeFalse();
    Http::assertNothingSent();
})->with(['endpoint', 'token']);

it('stays quiet while the application runs its tests', function () {
    Http::fake(['*' => Http::response(status: 202)]);
    $this->app['env'] = 'testing';

    app(Reporter::class)->report(new RuntimeException('Something broke'));

    Http::assertNothingSent();
});

it('waits until the response has been sent during a request', function () {
    Http::fake(['*' => Http::response(status: 202)]);
    $this->runningOverHttp();
    $reporter = app(Reporter::class);

    $reporter->report(new RuntimeException('Something broke'));

    Http::assertNothingSent();

    $this->app->terminate();

    expect(sentPayloads())->toHaveCount(1);
});

it('reports a failing request once it has been answered', function () {
    Http::fake(['*' => Http::response(status: 202)]);
    $this->runningOverHttp();
    Route::get('/broken', fn () => (new Thrower())->fail());

    $this->get('/broken')->assertServerError();

    expect(sentPayloads())->toHaveCount(1)
        ->and(sentPayloads()[0]['context']['request']['url'])->toBe(url('/broken'));
});

it('ignores failures while receiving a report from another application', function () {
    Http::fake(['*' => Http::response(status: 202)]);
    $this->runningOverHttp();
    Route::post('/api/boomerang', fn () => (new Thrower())->fail());

    $this->post('/api/boomerang', [], ['X-Boomerang' => '1.0.0'])->assertServerError();

    Http::assertNothingSent();
});

it('has nothing to send when nothing was reported', function () {
    Http::fake(['*' => Http::response(status: 202)]);
    app(Reporter::class)->flush();

    Http::assertNothingSent();
});

it('sends one occurrence per failure every few seconds and counts the others', function () {
    Http::fake(['*' => Http::response(status: 202)]);
    $this->usePackageAsBasePath();
    $reporter = app(Reporter::class);

    foreach (range(1, 3) as $n) {
        $reporter->report(caught(fn () => (new Thrower())->fail("Attempt {$n}")));
    }

    $this->travel(11)->seconds();
    $reporter->report(caught(fn () => (new Thrower())->fail('Attempt 4')));

    expect(array_column(sentPayloads(), 'skipped'))->toBe([0, 2]);
});

it('keeps reports for later when the endpoint is down, then sends them first', function () {
    Http::fakeSequence()->push(status: 503)->push(status: 202)->push(status: 202);
    $reporter = app(Reporter::class);

    $reporter->report(new RuntimeException('First'));

    expect(spoolCount())->toBe(1);

    $reporter->report(new LogicException('Second'));

    expect(spoolCount())->toBe(0)
        ->and(collect(sentPayloads())->pluck('exceptions.0.message')->all())->toBe(['First', 'Second', 'First']);
});

it('keeps every pending report when the endpoint goes down', function () {
    Http::fake(['*' => Http::response(status: 503)]);
    $this->runningOverHttp();
    $reporter = app(Reporter::class);

    $reporter->report(new RuntimeException('First'));
    $reporter->report(new LogicException('Second'));
    $reporter->flush();

    expect(sentPayloads())->toHaveCount(1)
        ->and(spoolCount())->toBe(2);
});

it('drops reports the endpoint rejects', function () {
    Http::fake(['*' => Http::response(status: 401)]);

    app(Reporter::class)->report(new RuntimeException('Something broke'));

    expect(spoolCount())->toBe(0);
});

it('never reports its own failures while sending', function () {
    Http::fake(function () {
        app(ExceptionHandler::class)->report(new LogicException('Failed while sending'));

        return Http::response(status: 202);
    });

    app(Reporter::class)->report(new RuntimeException('Something broke'));

    expect(sentPayloads())->toHaveCount(1);
});

it('never throws while reporting', function () {
    Http::fake(['*' => Http::response(status: 202)]);
    $this->mock(PayloadFactory::class)->shouldReceive('fingerprint')->andThrow(new RuntimeException('Broken factory'));

    app(Reporter::class)->report(new RuntimeException('Something broke'));

    Http::assertNothingSent();
});

it('never throws while sending pending reports', function () {
    Http::fake(['*' => Http::response(status: 503)]);
    config(['boomerang.storage_path' => '/dev/null/boomerang']);
    $this->runningOverHttp();
    $reporter = app(Reporter::class);

    $reporter->report(new RuntimeException('Something broke'));
    $reporter->flush();

    expect(sentPayloads())->toHaveCount(1);
});
