<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('CRM_REHEARSAL') !== '1' || ! str_starts_with(config('database.connections.pgsql.database'), 'base_rehearsal_')) {
    throw new RuntimeException('Rehearsal databases only.');
}
use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Setting;
use Awove\Core\Models\Status;
use Awove\Core\Services\Leads;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (($argv[1] ?? '') === 'seed') {
    Artisan::call('crm:install');
    $u = User::forceCreate(['name' => 'Pre-update fictional owner', 'email' => 'fixture@example.test', 'email_verified_at' => now(), 'password' => bin2hex(random_bytes(32)), 'active' => true, 'display_preferences' => ['columns' => ['title', 'status']]]);
    $u->assignRole('Employee');
    $c = Contact::create(['name' => 'Pre-update customer', 'created_by' => $u->id, 'search_key' => 'preupdate']);
    $lead = app(Leads::class)->create($u, ['title' => 'Pre-update lead', 'owner_id' => $u->id, 'contact_id' => $c->id, 'status_id' => 'new', 'priority' => 'normal', 'custom_fields' => config('core.installation') === 'beta' ? ['service_type' => 'Maintenance'] : []]);
    Status::find('new')->update(['name' => 'Retained customer status', 'position' => 42]);
    Setting::create(['key' => 'name', 'value' => 'Retained '.config('core.installation').' brand']);
    Setting::create(['key' => 'timezone', 'value' => 'Europe/London']);
    $u->givePermissionTo('records.archive');
    if (config('core.installation') === 'beta') {
        DB::table('service_details_leads')->where('lead_id', $lead->id)->update(['service_reference' => 'PRE-UPDATE-REF', 'requested_service' => 'Preserve extension data']);
    }
}
$data = [];
foreach (['users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'crm_contacts', 'crm_companies', 'crm_statuses', 'crm_settings', 'crm_leads', 'crm_tasks', 'crm_notes', 'crm_activity', 'service_details_leads'] as $table) {
    if (! Schema::hasTable($table)) {
        continue;
    }$rows = DB::table($table)->get()->map(function ($row) {
        $array = (array) $row;
        unset($array['external_reference']);
        ksort($array);

        return $array;
    })->all();
    usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
    $data[$table] = $rows;
}
$data['resolved_brand'] = Installation::settings();
echo hash('sha256', json_encode($data));
