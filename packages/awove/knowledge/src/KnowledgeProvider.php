<?php

namespace Awove\Knowledge;

use Awove\Core\Modules;
use Illuminate\Support\ServiceProvider;

class KnowledgeProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(Modules::class)->register(new KnowledgeModule);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
