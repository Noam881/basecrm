<?php

namespace Awove\Core;

use Awove\Core\Models\Lead;
use Awove\Core\Policies\LeadPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Modules::class);
        $this->mergeConfigFrom(__DIR__.'/../config/core.php', 'core');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        Gate::policy(Lead::class, LeadPolicy::class);
        $this->app->booted(fn () => app(Modules::class)->validate());
        if ($this->app->runningInConsole()) {
            $this->commands([Console\Install::class, Console\Remind::class, Console\Diagnose::class, Console\BootstrapAdmin::class]);
        }
    }
}
