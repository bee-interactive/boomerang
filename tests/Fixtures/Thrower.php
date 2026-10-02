<?php

namespace BeeInteractive\Boomerang\Tests\Fixtures;

use LogicException;
use RuntimeException;

class Thrower
{
    public function fail(string $message = 'Something broke'): never
    {
        throw new RuntimeException($message);
    }

    public function failAgain(): never
    {
        throw new RuntimeException('Something broke elsewhere');
    }

    public function failDifferently(): never
    {
        throw new LogicException('Something else broke');
    }

    public function recurse(int $depth): never
    {
        $depth === 0 ? $this->fail() : $this->recurse($depth - 1);
    }

    public function wrapped(): never
    {
        try {
            $this->fail('Inner failure');
        } catch (RuntimeException $exception) {
            throw new LogicException('Outer failure', 7, $exception);
        }
    }
}
