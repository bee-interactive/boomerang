<?php

use BeeInteractive\Boomerang\Redactor;

it('masks sensitive keys at any depth', function () {
    $redacted = app(Redactor::class)->redact([
        'name' => 'Yves',
        'Password' => 'hunter2',
        '_token' => 'csrf',
        'payment' => ['card_number' => '4242', 'amount' => 120, 'IBAN' => 'CH93'],
        'tags' => ['a', 'b'],
    ]);

    expect($redacted)->toBe([
        'name' => 'Yves',
        'Password' => '[redacted]',
        '_token' => '[redacted]',
        'payment' => ['card_number' => '[redacted]', 'amount' => 120, 'IBAN' => '[redacted]'],
        'tags' => ['a', 'b'],
    ]);
});

it('follows the configured list of sensitive keys', function () {
    config(['boomerang.redact' => ['nickname']]);

    expect(app(Redactor::class)->redact(['nickname' => 'yvo', 'password' => 'hunter2']))
        ->toBe(['nickname' => '[redacted]', 'password' => 'hunter2']);
});
