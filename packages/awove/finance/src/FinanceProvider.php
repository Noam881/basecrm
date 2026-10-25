<?php

namespace Awove\Finance;

use Awove\Core\Modules;
use Illuminate\Support\ServiceProvider;

class FinanceProvider extends ServiceProvider
{
    public function boot(Modules $modules): void
    {
        $modules->register(new FinanceModule);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/payroll.php');
    }
}
