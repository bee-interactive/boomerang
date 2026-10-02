<?php

use BeeInteractive\Boomerang\Exceptions\BoomerangTestException;
use Illuminate\Support\Facades\Http;

it('sends a test exception', function () {
    Http::fake(['*' => Http::response(status: 202)]);

    $this->artisan('boomerang:test')
        ->expectsOutputToContain('Test exception sent to Boomerang.')
        ->assertSuccessful();

    expect(sentPayloads()[0]['exceptions'][0]['class'])->toBe(BoomerangTestException::class);
});

it('fails when the endpoint rejects the token', function () {
    Http::fake(['*' => Http::response(status: 401)]);

    $this->artisan('boomerang:test')
        ->expectsOutputToContain('Check BOOMERANG_TOKEN.')
        ->assertFailed();
});

it('fails when the endpoint cannot be reached', function () {
    Http::fake(['*' => Http::response(status: 503)]);

    $this->artisan('boomerang:test')
        ->expectsOutputToContain('Check BOOMERANG_ENDPOINT.')
        ->assertFailed();
});

it('fails when it is not configured', function () {
    Http::fake();
    config(['boomerang.token' => null]);

    $this->artisan('boomerang:test')
        ->expectsOutputToContain('Set BOOMERANG_ENDPOINT and BOOMERANG_TOKEN.')
        ->assertFailed();

    Http::assertNothingSent();
});
