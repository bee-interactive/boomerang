<?php

namespace BeeInteractive\Boomerang;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Throwable;

class Transport
{
    public const HEADER = 'X-Boomerang';

    public function __construct(
        private readonly Factory $http,
        private readonly Repository $config,
    ) {}

    public function send(array $payload): Delivery
    {
        try {
            $response = $this->http
                ->withToken((string) $this->config->get('boomerang.token'))
                ->withHeaders([self::HEADER => Boomerang::version()])
                ->acceptJson()
                ->connectTimeout((float) $this->config->get('boomerang.connect_timeout', 1))
                ->timeout((float) $this->config->get('boomerang.timeout', 2))
                ->post((string) $this->config->get('boomerang.endpoint'), $payload);
        } catch (Throwable) {
            return Delivery::Retry;
        }

        return match (true) {
            $response->successful() => Delivery::Delivered,
            $response->serverError(), $response->tooManyRequests() => Delivery::Retry,
            default => Delivery::Rejected,
        };
    }
}
