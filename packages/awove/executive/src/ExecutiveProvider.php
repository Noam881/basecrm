<?php

namespace Awove\Executive;

use Awove\Core\Modules;
use Illuminate\Support\ServiceProvider;

class ExecutiveProvider extends ServiceProvider
{
    public function boot(): void
    {
        app(Modules::class)->register(new ExecutiveModule);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
