<?php

use BeeInteractive\Boomerang\Boomerang;
use BeeInteractive\Boomerang\PayloadFactory;
use BeeInteractive\Boomerang\Tests\Fixtures\Thrower;
use Composer\InstalledVersions;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Date;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

it('describes the exception, where it happened and what was running', function () {
    $this->usePackageAsBasePath();
    Date::setTestNow('2026-10-02 14:30:00');
    app('events')->dispatch(new CommandStarting('import:products', new ArrayInput([]), new NullOutput()));

    $payload = app(PayloadFactory::class)->make(caught(fn () => (new Thrower())->fail()), skipped: 3);

    expect($payload)
        ->notifier->toBe(['name' => 'boomerang', 'version' => Boomerang::version()])
        ->occurred_at->toBe('2026-10-02T14:30:00+00:00')
        ->skipped->toBe(3)
        ->context->toBe(['command' => ['name' => 'import:products']])
        ->and($payload['exceptions'])->toHaveCount(1)
        ->and($payload['exceptions'][0])
        ->class->toBe(RuntimeException::class)
        ->message->toBe('Something broke')
        ->code->toBe(0)
        ->and($payload['exceptions'][0]['frames'][0])
        ->file->toBe('tests/Fixtures/Thrower.php')
        ->function->toBe('fail');
});

it('follows the chain of previous exceptions', function () {
    $payload = app(PayloadFactory::class)->make(caught(fn () => (new Thrower())->wrapped()));

    expect($payload['exceptions'])->toHaveCount(2)
        ->and($payload['exceptions'][0])->class->toBe(LogicException::class)->message->toBe('Outer failure')->code->toBe(7)
        ->and($payload['exceptions'][1])->class->toBe(RuntimeException::class)->message->toBe('Inner failure');
});

it('stops following the chain after ten exceptions', function () {
    $exception = new RuntimeException('0');

    foreach (range(1, 11) as $n) {
        $exception = new RuntimeException((string) $n, previous: $exception);
    }

    expect(app(PayloadFactory::class)->make($exception)['exceptions'])->toHaveCount(10);
});

it('shortens very long messages', function () {
    $message = app(PayloadFactory::class)->make(new RuntimeException(str_repeat('a', 6000)))['exceptions'][0]['message'];

    expect(mb_strlen($message))->toBe(5003);
});

it('replaces invalid UTF-8 so the payload can be encoded', function () {
    $payload = app(PayloadFactory::class)->make(new RuntimeException("Invalid \xB1 byte"));

    expect($payload['exceptions'][0]['message'])->toBe('Invalid ? byte')
        ->and(json_encode($payload))->not->toBeFalse();
});

it('describes the environment', function () {
    $this->usePackageAsBasePath();
    file_put_contents(base_path('REVISION'), "689b60f5\n");

    try {
        $environment = app(PayloadFactory::class)->make(caught(fn () => (new Thrower())->fail()))['environment'];
    } finally {
        unlink(base_path('REVISION'));
    }

    expect($environment)
        ->name->toBe('production')
        ->revision->toBe('689b60f5')
        ->hostname->toBe(gethostname())
        ->php->toBe(PHP_VERSION)
        ->laravel->toBe(app()->version())
        ->packages->toHaveKey('pestphp/pest', InstalledVersions::getPrettyVersion('pestphp/pest'))
        ->packages->toHaveKey('phpunit/phpunit');
});

it('has no revision without a REVISION file', function () {
    expect(app(PayloadFactory::class)->make(new RuntimeException('x'))['environment']['revision'])->toBeNull();
});

it('has no revision when the REVISION file is empty', function () {
    $this->usePackageAsBasePath();
    file_put_contents(base_path('REVISION'), "\n");

    try {
        expect(app(PayloadFactory::class)->make(new RuntimeException('x'))['environment']['revision'])->toBeNull();
    } finally {
        unlink(base_path('REVISION'));
    }
});

it('gives the same fingerprint to the same failure at another line', function () {
    $this->usePackageAsBasePath();
    $payloads = app(PayloadFactory::class);

    $first = $payloads->fingerprint(caught(fn () => (new Thrower())->fail('First')));
    $second = $payloads->fingerprint(caught(fn () => (new Thrower())->fail('Second')));

    expect($first)->toBe($second)
        ->not->toBe($payloads->fingerprint(caught(fn () => (new Thrower())->failAgain())))
        ->not->toBe($payloads->fingerprint(caught(fn () => (new Thrower())->failDifferently())));
});

it('falls back to the file and line without any application frame', function () {
    $payloads = app(PayloadFactory::class);
    $exception = caught(fn () => (new Thrower())->fail());

    expect($payloads->fingerprint($exception))
        ->toBe(sha1(implode('|', [RuntimeException::class, $exception->getFile(), $exception->getLine()])));
});
