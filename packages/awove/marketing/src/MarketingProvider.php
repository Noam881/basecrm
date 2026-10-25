<?php

namespace Awove\Marketing;

use Awove\Core\Currency;
use Awove\Core\Modules;
use Illuminate\Support\ServiceProvider;

class MarketingProvider extends ServiceProvider
{
    public function boot(Modules $modules): void
    {
        $modules->register(new MarketingModule);
        app(Currency::class)->register('marketing', ['marketing_campaigns', 'marketing_performance_reports']);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
