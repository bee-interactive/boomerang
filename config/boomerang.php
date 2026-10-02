<?php

return [

    'endpoint' => env('BOOMERANG_ENDPOINT'),

    'token' => env('BOOMERANG_TOKEN'),

    'timeout' => (float) env('BOOMERANG_TIMEOUT', 2),

    'connect_timeout' => (float) env('BOOMERANG_CONNECT_TIMEOUT', 1),

    'throttle' => (int) env('BOOMERANG_THROTTLE', 10),

    'spool' => [
        'limit' => 100,
        'flush' => 10,
    ],

    'context_lines' => 5,

    'redact' => [
        'password',
        'token',
        'secret',
        'key',
        'card',
        'cvv',
        'iban',
        'avs',
        'ahv',
    ],

    'storage_path' => storage_path('boomerang'),

];
