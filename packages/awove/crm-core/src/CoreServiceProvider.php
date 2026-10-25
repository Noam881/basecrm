<?php

namespace Awove\Core;

use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Policies\IdentityPolicy;
use Awove\Core\Policies\LeadPolicy;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Modules::class);
        $this->app->singleton(Currency::class);
        $this->app->singleton(Services\WorkTypes::class);
        $this->mergeConfigFrom(__DIR__.'/../config/core.php', 'core');
    }

    public function boot(): void
    {
        app(Modules::class)->register(new SalesModule);
        app(Router::class)->aliasMiddleware('crm.module', Http\RequireModule::class);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/proposals.php');
        foreach (['sales', 'spreadsheets', 'work-reminders', 'employees'] as $routes) {
            if (is_file(__DIR__.'/../routes/'.$routes.'.php')) {
                $this->loadRoutesFrom(__DIR__.'/../routes/'.$routes.'.php');
            }
        }
        Gate::policy(Lead::class, LeadPolicy::class);
        Gate::policy(Contact::class, IdentityPolicy::class);
        Gate::policy(Company::class, IdentityPolicy::class);
        $this->app->booted(function () {
            app(Modules::class)->validate();
            app(Services\WorkTypes::class)->validate();
        });
        if ($this->app->runningInConsole()) {
            $this->commands([Console\Install::class, Console\Remind::class, Console\Diagnose::class, Console\BootstrapAdmin::class, Console\Permissions::class]);
        }
    }
}
