<?php

namespace Awove\Operations;

use Awove\Core\Modules;
use Illuminate\Support\ServiceProvider;

class OperationsProvider extends ServiceProvider
{
    public function boot(): void
    {
        app(Modules::class)->register(new OperationsModule);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
