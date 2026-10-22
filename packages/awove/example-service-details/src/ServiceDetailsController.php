<?php

namespace Awove\ServiceDetails;

use Awove\Core\Models\Lead;
use Awove\Core\Modules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ServiceDetailsController
{
    private function authorize(Lead $lead, string $action): void
    {
        abort_unless(app(Modules::class)->enabled('service-details'), 404);
        Gate::authorize('service-details.'.$action);
        Gate::authorize($action === 'edit' ? 'update' : 'view', $lead);
    }

    public function show(Lead $lead)
    {
        $this->authorize($lead, 'view');

        return response()->json(DB::table('service_details_leads')->where('lead_id', $lead->id)->first() ?? ['service_reference' => '', 'requested_service' => '']);
    }

    public function save(Request $r, Lead $lead)
    {
        $this->authorize($lead, 'edit');
        $data = $r->validate(['service_reference' => 'nullable|string|max:100', 'requested_service' => 'nullable|string|max:1000']);
        DB::table('service_details_leads')->updateOrInsert(['lead_id' => $lead->id], [...$data, 'updated_at' => now()]);

        return response()->json(['saved' => true]);
    }
}
