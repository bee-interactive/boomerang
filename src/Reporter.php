<?php

namespace BeeInteractive\Boomerang;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

class Reporter
{
    private array $pending = [];

    private bool $busy = false;

    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
        private readonly PayloadFactory $payloads,
        private readonly Throttle $throttle,
        private readonly Transport $transport,
        private readonly Spool $spool,
    ) {}

    public function enabled(): bool
    {
        return filled($this->config->get('boomerang.endpoint'))
            && filled($this->config->get('boomerang.token'))
            && ! $this->app->runningUnitTests();
    }

    public function report(Throwable $exception): void
    {
        if ($this->busy || ! $this->enabled() || $this->fromAnotherReporter()) {
            return;
        }

        $this->busy = true;

        try {
            $skipped = $this->throttle->attempt($this->payloads->fingerprint($exception));

            if ($skipped !== null) {
                $this->dispatch($this->payloads->make($exception, $skipped));
            }
        } catch (Throwable) {
        } finally {
            $this->busy = false;
        }
    }

    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $payloads = $this->pending;
        $this->pending = [];
        $this->busy = true;

        try {
            $this->deliver($payloads);
        } catch (Throwable) {
        } finally {
            $this->busy = false;
        }
    }

    private function dispatch(array $payload): void
    {
        if ($this->app->runningInConsole()) {
            $this->deliver([$payload]);

            return;
        }

        $this->pending[] = $payload;

        if (! $this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function([$this, 'flush']);
        }
    }

    private function deliver(array $payloads): void
    {
        foreach ($payloads as $index => $payload) {
            if ($this->transport->send($payload) === Delivery::Retry) {
                array_map($this->spool->store(...), array_slice($payloads, $index));

                return;
            }
        }

        $this->spool->flush($this->transport);
    }

    private function fromAnotherReporter(): bool
    {
        return ! $this->app->runningInConsole()
            && $this->app->make('request')->headers->has(Transport::HEADER);
    }
}
