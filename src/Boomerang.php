<?php

namespace BeeInteractive\Boomerang;

use Composer\InstalledVersions;

final class Boomerang
{
    public const PACKAGE = 'bee-interactive/boomerang';

    public static function version(): string
    {
        return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'unknown';
    }
}
