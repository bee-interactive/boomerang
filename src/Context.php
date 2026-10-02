<?php

namespace BeeInteractive\Boomerang;

use Illuminate\Auth\SessionGuard;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Symfony\Component\HttpFoundation\IpUtils;

class Context
{
    private array $commands = [];

    private ?array $job = null;

    public function __construct(
        private readonly Application $app,
        private readonly Redactor $redactor,
    ) {}

    public function commandStarting(CommandStarting $event): void
    {
        $this->commands[] = $event->command;
    }

    public function commandFinished(CommandFinished $event): void
    {
        array_pop($this->commands);
    }

    public function jobProcessing(JobProcessing $event): void
    {
        $this->job = [
            'name' => $event->job->resolveName(),
            'connection' => $event->connectionName,
            'queue' => $event->job->getQueue(),
            'attempts' => $event->job->attempts(),
        ];
    }

    public function jobProcessed(JobProcessed $event): void
    {
        $this->job = null;
    }

    public function collect(): array
    {
        return array_filter([
            'request' => rescue(fn () => $this->request(), report: false),
            'command' => $this->commands === [] ? null : ['name' => end($this->commands)],
            'job' => $this->job,
            'user' => rescue(fn () => $this->user(), report: false),
        ]);
    }

    private function request(): ?array
    {
        if ($this->app->runningInConsole()) {
            return null;
        }

        $request = $this->app->make('request');
        $route = $request->route();

        return [
            'method' => $request->method(),
            'url' => $request->url(),
            'route' => $route?->getName(),
            'action' => $route?->getActionName(),
            'input' => $this->redactor->redact($request->input()),
            'files' => array_keys($request->allFiles()),
            'headers' => array_filter([
                'user-agent' => $request->userAgent(),
                'referer' => $request->headers->get('referer'),
                'accept-language' => $request->headers->get('accept-language'),
            ]),
            'ip' => $request->ip() === null ? null : IpUtils::anonymize($request->ip()),
        ];
    }

    private function user(): ?array
    {
        foreach (array_keys($this->app->make('config')->get('auth.guards', [])) as $name) {
            $user = rescue(fn () => $this->guardUser($name), report: false);

            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }

    private function guardUser(string $name): ?array
    {
        $guard = $this->app->make('auth')->guard($name);

        if ($guard->hasUser()) {
            return [
                'guard' => $name,
                'id' => $guard->user()->getAuthIdentifier(),
                'type' => $guard->user()::class,
            ];
        }

        if ($guard instanceof SessionGuard && $guard->getSession()->has($guard->getName())) {
            return [
                'guard' => $name,
                'id' => $guard->getSession()->get($guard->getName()),
                'type' => null,
            ];
        }

        return null;
    }
}
