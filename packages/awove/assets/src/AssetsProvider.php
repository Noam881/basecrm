<?php

namespace Awove\Assets;

use Awove\Core\Currency;
use Awove\Core\Modules;
use Illuminate\Support\ServiceProvider;

class AssetsProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(Modules::class)->register(new AssetsModule);
        app(Currency::class)->register('assets', ['assets_maintenance_costs']);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
