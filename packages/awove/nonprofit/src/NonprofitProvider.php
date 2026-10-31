<?php

namespace Awove\Nonprofit;

use Awove\Core\Currency;
use Awove\Core\Modules;
use Illuminate\Support\ServiceProvider;

class NonprofitProvider extends ServiceProvider
{
    public function boot(Modules $modules): void
    {
        $modules->register(new NonprofitModule);
        app(Currency::class)->register('nonprofit', ['nonprofit_pledges', 'nonprofit_project_budgets']);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        foreach (['web', 'programs', 'fundraising', 'projects'] as $routes) {
            $this->loadRoutesFrom(__DIR__.'/../routes/'.$routes.'.php');
        }
    }
}
