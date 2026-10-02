<?php

use BeeInteractive\Boomerang\Boomerang;
use BeeInteractive\Boomerang\Delivery;
use BeeInteractive\Boomerang\Transport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('posts the payload to the endpoint with the site token', function () {
    Http::fake(['*' => Http::response(status: 202)]);

    expect(app(Transport::class)->send(['skipped' => 0]))->toBe(Delivery::Delivered);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://interactive.test/api/boomerang/albishorn'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer site-token')
        && $request->hasHeader('X-Boomerang', Boomerang::version())
        && $request->hasHeader('Accept', 'application/json')
        && $request->data() === ['skipped' => 0]);
});

it('asks for a retry when the endpoint is unavailable', function (int $status) {
    Http::fake(['*' => Http::response(status: $status)]);

    expect(app(Transport::class)->send([]))->toBe(Delivery::Retry);
})->with([500, 503, 429]);

it('asks for a retry when the endpoint cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(app(Transport::class)->send([]))->toBe(Delivery::Retry);
});

it('drops the payload when the endpoint rejects it', function (int $status) {
    Http::fake(['*' => Http::response(status: $status)]);

    expect(app(Transport::class)->send([]))->toBe(Delivery::Rejected);
})->with([401, 403, 413, 422]);
