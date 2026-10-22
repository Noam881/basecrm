<?php

use App\Models\User;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Services\Leads;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$installation = $argv[1] ?? 'alpha';
if (! in_array($installation, ['alpha', 'beta'])) {
    throw new RuntimeException('Local examples only.');
}$app->loadEnvironmentFrom('.env.'.$installation);
$app->make(Kernel::class)->bootstrap();
if (! app()->isLocal() || config('database.connections.pgsql.database') !== 'base_'.$installation) {
    throw new RuntimeException('Synthetic local example database required.');
}
$file = base_path('.local-fixtures.json');
$credentials = json_decode(file_get_contents($file), true);
foreach (['desktop', 'mobile'] as $size) {
    $key = 'tables_'.$installation.'_'.$size;
    $credentials[$key] ??= ['email' => 'table-'.$size.'@'.$installation.'.example.test', 'password' => bin2hex(random_bytes(18))];
    $u = User::firstOrNew(['email' => $credentials[$key]['email']]);
    $u->forceFill(['name' => ucfirst($installation).' table review', 'password' => $credentials[$key]['password'], 'email_verified_at' => now(), 'display_preferences' => null, 'active' => true])->save();
    $u->assignRole('Employee');
    if ($installation === 'beta') {
        $u->givePermissionTo(['service-details.view', 'service-details.edit']);
    }
    if (! Lead::where('owner_id', $u->id)->exists()) {
        foreach ([['Harbor Studio', 'Website refresh', 'high'], ['Northline Supply', 'Service consultation', 'normal'], ['Juniper & Co', 'Annual maintenance', 'low']] as [$name,$title,$priority]) {
            $company = Company::create(['name' => $name, 'created_by' => $u->id, 'search_key' => strtolower($name)]);
            $contact = Contact::create(['name' => $name.' contact', 'email' => strtolower(str_replace(' ', '', $name)).'@example.test', 'company_id' => $company->id, 'created_by' => $u->id, 'search_key' => strtolower($name)]);
            $lead = app(Leads::class)->create($u, ['title' => $title, 'company_id' => $company->id, 'contact_id' => $contact->id, 'owner_id' => $u->id, 'status_id' => 'new', 'source' => 'Referral', 'priority' => $priority, 'custom_fields' => $installation === 'beta' ? ['service_type' => 'Maintenance'] : []]);
            if ($installation === 'beta') {
                DB::table('service_details_leads')->where('lead_id', $lead->id)->update(['service_reference' => $name === 'Harbor Studio' ? 'DEMO-REF' : 'TABLE-REF', 'requested_service' => 'Fictional service']);
            }
        }
    }
}
file_put_contents($file, json_encode($credentials, JSON_PRETTY_PRINT));
chmod($file, 0600);
echo "Isolated $installation table browser fixtures ready.\n";
