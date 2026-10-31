<?php

namespace Awove\Finance;

use Awove\Core\Currency as InstallationCurrency;

class Currency
{
    public const ERROR = InstallationCurrency::ERROR;

    public static function current(): string
    {
        return InstallationCurrency::current();
    }

    public static function consistent(): bool
    {
        return app(InstallationCurrency::class)->consistent();
    }

    public static function assertConsistent(): void
    {
        app(InstallationCurrency::class)->assertConsistent();
    }
}
