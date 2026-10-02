<?php

use BeeInteractive\Boomerang\Throttle;

it('lets the first occurrence through', function () {
    expect(app(Throttle::class)->attempt('fingerprint'))->toBe(0);
});

it('holds back repeated occurrences and counts them for the next one', function () {
    $throttle = app(Throttle::class);

    $throttle->attempt('fingerprint');

    expect($throttle->attempt('fingerprint'))->toBeNull()
        ->and($throttle->attempt('fingerprint'))->toBeNull()
        ->and($throttle->attempt('other'))->toBe(0);

    $this->travel(11)->seconds();

    expect($throttle->attempt('fingerprint'))->toBe(2)
        ->and($throttle->attempt('fingerprint'))->toBeNull();
});

it('follows the configured window', function () {
    config(['boomerang.throttle' => 60]);
    $throttle = app(Throttle::class);

    $throttle->attempt('fingerprint');
    $this->travel(30)->seconds();

    expect($throttle->attempt('fingerprint'))->toBeNull();
});

it('lets everything through when its storage is unusable', function () {
    config(['boomerang.storage_path' => '/dev/null/boomerang']);
    $throttle = app(Throttle::class);

    expect($throttle->attempt('fingerprint'))->toBe(0)
        ->and($throttle->attempt('fingerprint'))->toBe(0);
});
