<?php

use BeeInteractive\Boomerang\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(TestCase::class)->in(__DIR__);

function caught(callable $callback): Throwable
{
    try {
        $callback();
    } catch (Throwable $exception) {
        return $exception;
    }

    throw new LogicException('Nothing was thrown.');
}

function sentPayloads(): array
{
    return Http::recorded()->map(fn (array $pair) => $pair[0])->map(fn (Request $request) => $request->data())->values()->all();
}
