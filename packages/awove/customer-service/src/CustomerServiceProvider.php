<?php

namespace Awove\CustomerService;

use Awove\Core\Modules;
use Illuminate\Support\ServiceProvider;

class CustomerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        app(Modules::class)->register(new CustomerServiceModule);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
