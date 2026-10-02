<?php

use BeeInteractive\Boomerang\Context;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

function syncJob(): SyncJob
{
    return new SyncJob(app(), json_encode(['displayName' => 'App\\Jobs\\ImportProducts', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []]), 'sync', 'default');
}

function user(int $id): User
{
    return (new User())->forceFill(['id' => $id]);
}

it('has nothing to tell outside of a request, command or job', function () {
    expect(app(Context::class)->collect())->toBe([]);
});

it('describes the current request', function () {
    $this->runningOverHttp();
    Route::post('/invoices/{invoice}', fn () => app(Context::class)->collect())->name('invoices.update');

    $context = $this->call('POST', '/invoices/12?draft=1', ['total' => 120, 'password' => 'hunter2'], [], [
        'pdf' => UploadedFile::fake()->create('invoice.pdf'),
    ], [
        'HTTP_USER_AGENT' => 'Mozilla/5.0',
        'HTTP_REFERER' => 'https://albishorn.ch/invoices',
        'HTTP_ACCEPT_LANGUAGE' => 'fr-CH',
        'HTTP_COOKIE' => 'laravel_session=abc',
        'REMOTE_ADDR' => '203.0.113.42',
    ])->json();

    expect($context['request'])->toBe([
        'method' => 'POST',
        'url' => url('/invoices/12'),
        'route' => 'invoices.update',
        'action' => 'Closure',
        'input' => ['total' => 120, 'password' => '[redacted]', 'draft' => '1'],
        'files' => ['pdf'],
        'headers' => [
            'user-agent' => 'Mozilla/5.0',
            'referer' => 'https://albishorn.ch/invoices',
            'accept-language' => 'fr-CH',
        ],
        'ip' => '203.0.113.0',
    ]);
});

it('describes a request that matched no route and has no address', function () {
    $this->runningOverHttp();
    $request = Request::create('/missing');
    $request->server->remove('REMOTE_ADDR');
    $this->app->instance('request', $request);

    expect(app(Context::class)->collect()['request'])
        ->route->toBeNull()
        ->action->toBeNull()
        ->headers->toBe(['user-agent' => 'Symfony', 'accept-language' => 'en-us,en;q=0.5'])
        ->ip->toBeNull();
});

it('skips the request when it cannot be read', function () {
    $this->runningOverHttp();
    $this->app->bind('request', fn () => throw new RuntimeException('No request'));

    expect(app(Context::class)->collect())->not->toHaveKey('request');
});

it('names the running command, back to the outer one when a nested command ends', function () {
    $events = app('events');
    $context = app(Context::class);

    $events->dispatch(new CommandStarting('import:products', new ArrayInput([]), new NullOutput()));
    $events->dispatch(new CommandStarting('cache:clear', new ArrayInput([]), new NullOutput()));

    expect($context->collect()['command'])->toBe(['name' => 'cache:clear']);

    $events->dispatch(new CommandFinished('cache:clear', new ArrayInput([]), new NullOutput(), 0));

    expect($context->collect()['command'])->toBe(['name' => 'import:products']);

    $events->dispatch(new CommandFinished('import:products', new ArrayInput([]), new NullOutput(), 0));

    expect($context->collect())->not->toHaveKey('command');
});

it('describes the job being processed until it succeeds', function () {
    $context = app(Context::class);

    app('events')->dispatch(new JobProcessing('sync', syncJob()));

    expect($context->collect()['job'])->toBe([
        'name' => 'App\\Jobs\\ImportProducts',
        'connection' => 'sync',
        'queue' => 'sync',
        'attempts' => 1,
    ]);

    app('events')->dispatch(new JobProcessed('sync', syncJob()));

    expect($context->collect())->not->toHaveKey('job');
});

it('identifies the signed in user without loading anything else', function () {
    $this->actingAs(user(42));

    expect(app(Context::class)->collect()['user'])->toBe([
        'guard' => 'web',
        'id' => 42,
        'type' => User::class,
    ]);
});

it('reads the user id from the session when the user was never loaded', function () {
    $guard = auth()->guard('web');
    $guard->getSession()->put($guard->getName(), 42);

    expect(app(Context::class)->collect()['user'])->toBe([
        'guard' => 'web',
        'id' => 42,
        'type' => null,
    ]);
});

it('looks through every guard', function () {
    config(['auth.guards.cockpit' => ['driver' => 'session', 'provider' => 'users']]);
    $this->actingAs(user(7), 'cockpit');

    expect(app(Context::class)->collect()['user'])->toBe([
        'guard' => 'cockpit',
        'id' => 7,
        'type' => User::class,
    ]);
});

it('ignores guards that cannot be built', function () {
    config(['auth.guards' => ['broken' => ['driver' => 'missing'], 'web' => ['driver' => 'session', 'provider' => 'users']]]);
    $this->actingAs(user(42));

    expect(app(Context::class)->collect()['user']['id'])->toBe(42);
});

it('ignores guards without a session', function () {
    config(['auth.guards' => ['api' => ['driver' => 'token', 'provider' => 'users']]]);

    expect(app(Context::class)->collect())->not->toHaveKey('user');
});
