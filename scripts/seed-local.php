<?php

use App\Models\User;
use Awove\Core\Models\Company;
use Awove\Core\Models\Lead;
use Awove\Core\Services\Leads;
use Illuminate\Contracts\Console\Kernel;

// Local-only synthetic fixtures. Random private credentials, no production defaults.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
if (isset($argv[1])) {
    if (! in_array($argv[1], ['alpha', 'beta'])) {
        throw new RuntimeException('Unknown example.');
    }$app->loadEnvironmentFrom('.env.'.$argv[1]);
}
$app->make(Kernel::class)->bootstrap();
if (! app()->isLocal() || ! in_array(config('database.connections.pgsql.database'), ['base_alpha', 'base_beta'])) {
    throw new RuntimeException('Only synthetic local example databases are permitted.');
}
$path = base_path('.local-fixtures.json');
$credentials = is_file($path) ? json_decode(file_get_contents($path), true) : [];
$id = config('core.installation');
$credentials[$id]['password'] ??= bin2hex(random_bytes(18));
unset($credentials['password']);
$email = 'reviewer@'.$id.'.example.test';
$user = User::firstOrNew(['email' => $email]);
if (! $user->exists) {
    $user->forceFill(['name' => $id === 'alpha' ? 'Alex Morgan' : 'Jordan Ellis', 'email_verified_at' => now(), 'password' => $credentials[$id]['password'], 'active' => true])->save();
    $user->assignRole('Employee');
    if ($id === 'beta') {
        $user->givePermissionTo(['service-details.view', 'service-details.edit']);
    }
}
$user->forceFill(['password' => $credentials[$id]['password']])->save();
$credentials[$id]['email'] = $email;
file_put_contents($path, json_encode($credentials, JSON_PRETTY_PRINT));
chmod($path, 0600);
if (! Lead::where('created_by', $user->id)->exists()) {
    foreach ([['Harbor Studio', 'Website refresh', 'qualified'], ['Northline Supply', 'Service consultation', 'contacted'], ['Juniper & Co', 'Annual maintenance', 'new']] as [$name,$title,$status]) {
        $company = Company::create(['name' => $name, 'email' => strtolower(str_replace(' ', '', $name)).'@example.test', 'created_by' => $user->id, 'search_key' => mb_strtolower(preg_replace('/[^a-zA-Z]/', '', $name))]);
        $lead = app(Leads::class)->create($user, ['title' => $title, 'company_id' => $company->id, 'owner_id' => $user->id, 'status_id' => $status, 'source' => 'Referral', 'priority' => 'normal']);
        $lead->notes()->create(['body' => 'Fictional review data. No live customer relationship.', 'author_id' => $user->id]);
        $lead->tasks()->create(['title' => 'Check in with '.$name, 'assignee_id' => $user->id, 'due_at' => now()->addHours(2), 'remind_at' => now()->subMinute(), 'priority' => 'normal']);
    }
}
echo "Synthetic $id fixtures ready. Credentials are in .local-fixtures.json (private, ignored).\n";
