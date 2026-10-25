<?php

namespace Awove\Hr;

use Awove\Core\Modules;
use Illuminate\Support\ServiceProvider;

class HrProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(Modules::class)->register(new HrModule);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
