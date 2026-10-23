<?php

namespace Awove\ServiceDetails;

use Awove\Core\Events\LeadCreated;
use Awove\Core\Modules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ServiceDetailsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        app(Modules::class)->register(new ServiceDetailsModule);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        Route::middleware(['web', 'auth', 'verified'])->prefix('modules/service-details')->name('service-details.')->group(function () {
            Route::get('/leads/{lead}', [ServiceDetailsController::class, 'show'])->name('show');
            Route::put('/leads/{lead}', [ServiceDetailsController::class, 'save'])->name('save');
        });
        Event::listen(LeadCreated::class, function (LeadCreated $event) {
            if (! app(Modules::class)->enabled('service-details')) {
                return;
            }DB::table('service_details_leads')->insertOrIgnore(['lead_id' => $event->leadId, 'service_reference' => null, 'requested_service' => null, 'created_from_event' => true, 'created_at' => now(), 'updated_at' => now()]);
        });
    }
}
