<?php

namespace BeeInteractive\Boomerang;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;

class Redactor
{
    public const MASK = '[redacted]';

    public function __construct(private readonly Repository $config) {}

    public function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = match (true) {
                is_string($key) && $this->sensitive($key) => self::MASK,
                is_array($value) => $this->redact($value),
                default => $value,
            };
        }

        return $data;
    }

    private function sensitive(string $key): bool
    {
        return Str::contains($key, $this->config->get('boomerang.redact', []), ignoreCase: true);
    }
}
