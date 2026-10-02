<?php

namespace BeeInteractive\Boomerang\Console;

use BeeInteractive\Boomerang\Delivery;
use BeeInteractive\Boomerang\Exceptions\BoomerangTestException;
use BeeInteractive\Boomerang\PayloadFactory;
use BeeInteractive\Boomerang\Reporter;
use BeeInteractive\Boomerang\Transport;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'boomerang:test')]
class TestCommand extends Command
{
    protected $signature = 'boomerang:test';

    protected $description = 'Send a test exception to Boomerang';

    public function handle(Reporter $reporter, PayloadFactory $payloads, Transport $transport): int
    {
        if (! $reporter->enabled()) {
            $this->components->error('Boomerang is not configured. Set BOOMERANG_ENDPOINT and BOOMERANG_TOKEN.');

            return self::FAILURE;
        }

        $delivery = $transport->send($payloads->make(new BoomerangTestException('Boomerang test exception.')));

        return match ($delivery) {
            Delivery::Delivered => $this->succeed(),
            Delivery::Rejected => $this->failWith('The endpoint rejected the test exception. Check BOOMERANG_TOKEN.'),
            Delivery::Retry => $this->failWith('The endpoint could not be reached. Check BOOMERANG_ENDPOINT.'),
        };
    }

    private function succeed(): int
    {
        $this->components->info('Test exception sent to Boomerang.');

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
