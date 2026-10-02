<?php

use BeeInteractive\Boomerang\Spool;
use BeeInteractive\Boomerang\Transport;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;

function spooled(): array
{
    $directory = config('boomerang.storage_path').'/spool';

    return is_dir($directory) ? array_map(fn ($file) => json_decode($file->getContents(), true), (new Filesystem())->files($directory)) : [];
}

it('keeps payloads on disk in the order they came', function () {
    $spool = app(Spool::class);

    $spool->store(['n' => 1]);
    $spool->store(['n' => 2]);

    expect(spooled())->toBe([['n' => 1], ['n' => 2]]);
});

it('stops keeping payloads once full', function () {
    config(['boomerang.spool.limit' => 2]);
    $spool = app(Spool::class);

    foreach (range(1, 3) as $n) {
        $spool->store(['n' => $n]);
    }

    expect(spooled())->toBe([['n' => 1], ['n' => 2]]);
});

it('stores payloads with invalid UTF-8', function () {
    app(Spool::class)->store(['message' => "broken \xB1 byte"]);

    expect(spooled()[0]['message'])->toBe("broken \u{FFFD} byte");
});

it('sends the oldest payloads and forgets them', function () {
    Http::fake(['*' => Http::response(status: 202)]);
    config(['boomerang.spool.flush' => 2]);
    $spool = app(Spool::class);

    foreach (range(1, 3) as $n) {
        $spool->store(['n' => $n]);
    }

    $spool->flush(app(Transport::class));

    expect(sentPayloads())->toBe([['n' => 1], ['n' => 2]])
        ->and(spooled())->toBe([['n' => 3]]);
});

it('stops at the first payload the endpoint cannot take yet', function () {
    Http::fakeSequence()->push(status: 202)->push(status: 503);
    $spool = app(Spool::class);

    foreach (range(1, 3) as $n) {
        $spool->store(['n' => $n]);
    }

    $spool->flush(app(Transport::class));

    expect(sentPayloads())->toHaveCount(2)
        ->and(spooled())->toBe([['n' => 2], ['n' => 3]]);
});

it('forgets payloads the endpoint rejects', function () {
    Http::fake(['*' => Http::response(status: 422)]);
    $spool = app(Spool::class);

    $spool->store(['n' => 1]);
    $spool->flush(app(Transport::class));

    expect(spooled())->toBe([]);
});

it('forgets unreadable files without sending them', function () {
    Http::fake();
    $directory = config('boomerang.storage_path').'/spool';
    (new Filesystem())->ensureDirectoryExists($directory);
    file_put_contents($directory.'/broken.json', '{not json');

    app(Spool::class)->flush(app(Transport::class));

    Http::assertNothingSent();
    expect(is_file($directory.'/broken.json'))->toBeFalse();
});

it('has nothing to send before anything was spooled', function () {
    Http::fake();

    app(Spool::class)->flush(app(Transport::class));

    Http::assertNothingSent();
});
