<?php

use BeeInteractive\Boomerang\Stacktrace;
use BeeInteractive\Boomerang\Tests\Fixtures\Thrower;
use Illuminate\Filesystem\Filesystem;

function compiledView(string $directory, string $contents): string
{
    (new Filesystem())->ensureDirectoryExists($directory);
    file_put_contents($path = $directory.'/'.md5($contents).'.php', $contents);

    return $path;
}

it('starts at the line that threw, inside the method that threw', function () {
    $this->usePackageAsBasePath();

    $frame = app(Stacktrace::class)->frames(caught(fn () => (new Thrower())->fail()))[0];

    expect($frame)
        ->file->toBe('tests/Fixtures/Thrower.php')
        ->line->toBe(12)
        ->class->toBe(Thrower::class)
        ->function->toBe('fail')
        ->in_app->toBeTrue()
        ->compiled->toBeNull();
});

it('shows the code around application frames', function () {
    $this->usePackageAsBasePath();

    $frame = app(Stacktrace::class)->frames(caught(fn () => (new Thrower())->fail()))[0];

    expect($frame['code'])->toHaveKeys(range(7, 17))
        ->and($frame['code'][12])->toBe('        throw new RuntimeException($message);');
});

it('follows the configured amount of surrounding code', function () {
    $this->usePackageAsBasePath();
    config(['boomerang.context_lines' => 1]);

    $frame = app(Stacktrace::class)->frames(caught(fn () => (new Thrower())->fail()))[0];

    expect(array_keys($frame['code']))->toBe([11, 12, 13]);
});

it('leaves vendor frames out of the application', function () {
    $this->usePackageAsBasePath();

    $vendor = collect(app(Stacktrace::class)->frames(caught(fn () => (new Thrower())->fail())))
        ->first(fn (array $frame) => str_starts_with((string) $frame['file'], 'vendor/'));

    expect($vendor)->in_app->toBeFalse()->code->toBeNull();
});

it('keeps paths outside the application absolute', function () {
    $frame = app(Stacktrace::class)->frames(caught(fn () => (new Thrower())->fail()))[0];

    expect($frame)
        ->file->toBe(dirname(__DIR__).'/Fixtures/Thrower.php')
        ->in_app->toBeFalse()
        ->code->toBeNull();
});

it('can skip reading the code', function () {
    $this->usePackageAsBasePath();

    $frames = app(Stacktrace::class)->frames(caught(fn () => (new Thrower())->fail()), withCode: false);

    expect(array_column($frames, 'code'))->each->toBeNull();
});

it('keeps frames without a file', function () {
    $frames = app(Stacktrace::class)->frames(caught(fn () => array_map(fn () => (new Thrower())->fail(), [1])));

    expect(collect($frames)->firstWhere('file', null))
        ->line->toBeNull()
        ->in_app->toBeFalse();
});

it('stops after a hundred frames', function () {
    expect(app(Stacktrace::class)->frames(caught(fn () => (new Thrower())->recurse(150))))->toHaveCount(100);
});

it('points compiled views to their Blade template', function () {
    $this->usePackageAsBasePath();
    config(['view.compiled' => $directory = base_path('tests/compiled')]);
    $view = compiledView($directory, "<?php\n\nthrow new RuntimeException('Undefined variable \$page');\n?>\n<?php /**PATH ".base_path('resources/views/welcome.blade.php').' ENDPATH**/ ?>');

    try {
        $frame = app(Stacktrace::class)->frames(caught(fn () => require $view))[0];
    } finally {
        (new Filesystem())->deleteDirectory($directory);
    }

    expect($frame)
        ->file->toBe('resources/views/welcome.blade.php')
        ->line->toBeNull()
        ->compiled->toBe(['file' => 'tests/compiled/'.basename($view), 'line' => 3])
        ->in_app->toBeTrue()
        ->and($frame['code'][3])->toBe("throw new RuntimeException('Undefined variable \$page');");
});

it('keeps compiled views it cannot trace back', function () {
    $this->usePackageAsBasePath();
    config(['view.compiled' => $directory = base_path('tests/compiled')]);
    $view = compiledView($directory, "<?php\n\nthrow new RuntimeException('No marker');\n");

    try {
        $frame = app(Stacktrace::class)->frames(caught(fn () => require $view))[0];
    } finally {
        (new Filesystem())->deleteDirectory($directory);
    }

    expect($frame)
        ->file->toBe('tests/compiled/'.basename($view))
        ->line->toBe(3)
        ->compiled->toBeNull();
});

it('works without a compiled views directory', function () {
    config(['view.compiled' => null]);

    expect(app(Stacktrace::class)->frames(caught(fn () => (new Thrower())->fail()))[0]['compiled'])->toBeNull();
});

it('gives no code when the file has gone', function () {
    $this->usePackageAsBasePath();
    $path = base_path('tests/Gone.php');
    file_put_contents($path, "<?php\n\nthrow new RuntimeException('Gone');\n");
    $exception = caught(fn () => require $path);
    unlink($path);

    expect(app(Stacktrace::class)->frames($exception)[0])
        ->in_app->toBeTrue()
        ->code->toBeNull();
});
