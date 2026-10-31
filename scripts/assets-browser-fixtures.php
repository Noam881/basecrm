<?php

use App\Models\User;
use Awove\Assets\AssetsModule;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Services\EmployeeReferences;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/fictional-fixtures.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.alpha');
$app->make(Kernel::class)->bootstrap();
FictionalCrmFixtures::assertLocalExample('alpha');
$actual = DB::selectOne('select current_database() as name,current_user as role');
if (config('database.connections.pgsql.port') != 55438 || $actual->name !== 'base_alpha' || $actual->role !== 'base_alpha' || config('mail.default') !== 'smtp' || config('mail.mailers.smtp.host') !== '127.0.0.1' || config('mail.mailers.smtp.port') != 1028) {
    throw new RuntimeException('Only isolated Alpha and local Mailpit are permitted.');
}
$mode = $argv[1] ?? '';
$project = $argv[2] ?? '';
if (! in_array($mode, ['setup', 'cleanup'], true) || ! in_array($project, ['desktop', 'mobile'], true)) {
    throw new RuntimeException('Choose setup or cleanup and a known browser project.');
}
if (! Schema::hasTable('assets_register')) {
    throw new RuntimeException('Apply the controlled additive Assets migration to isolated Alpha first.');
}
$email = 'assets-'.$project.'@alpha.example.test';
$contactEmail = 'assets-customer-'.$project.'@alpha.example.test';
$companyEmail = 'assets-supplier-'.$project.'@alpha.example.test';
$user = User::where('email', $email)->first();
if ($user && $user->name !== 'Noa Cohen') {
    throw new RuntimeException('A protected account uses the reserved fixture email.');
}
/** Any business record outside the explicit owned fixture graph must stop cleanup. */
function assetsFixtureForeign(string $table, $ids, array $allowed): void
{
    $keys = DB::select("SELECT c.conrelid::regclass::text AS table_name,a.attname AS column_name FROM pg_constraint c JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=c.conkey[1] WHERE c.contype='f' AND c.confrelid=?::regclass", [$table]);
    foreach ($keys as $key) {
        if (! in_array($key->table_name, $allowed, true) && DB::table($key->table_name)->whereIn($key->column_name, $ids)->exists()) {
            throw new RuntimeException('A protected foreign relationship references the reserved Assets fixture.');
        }
    }
}
$result = DB::transaction(function () use ($mode, $project, $email, $contactEmail, $companyEmail, $user) {
    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-assets-fixtures']);
    if ($mode === 'setup') {
        Artisan::call('crm:install');
        $fixture = $user ?? User::factory()->create(['name' => 'Noa Cohen', 'email' => $email, 'email_verified_at' => now()]);
        $fixture->forceFill(['active' => true, 'display_preferences' => null])->save();
        $fixture->assignRole('Employee');
        $permissions = array_values(array_diff((new AssetsModule)->permissions(), ['assets.view.all', 'assets.edit.all']));
        $fixture->syncPermissions([...$permissions, 'employees.references.view', 'employees.references.manage', 'identities.classifications.view', 'identities.classifications.manage']);
        $manifest = Installation::manifest();
        $manifest['modules'] = ['assets'];
        $manifest['capabilities'] = ['sales' => false, 'finance:collections' => false, 'finance:expenses' => false, 'finance:payroll' => false];
        $path = storage_path('app/private/assets-browser-manifest.json');
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        config(['core.manifest' => $path]);
        $employee = app(EmployeeReferences::class)->create($fixture, ['name' => 'Yael Shalev', 'active' => true]);
        if (Contact::where('email', $contactEmail)->exists() || Company::where('email', $companyEmail)->exists()) {
            throw new RuntimeException('An existing identity uses a reserved fixture email; cleanup must run first.');
        }
        $contact = Contact::create(['name' => 'Noam Levi', 'email' => $contactEmail, 'phone' => '+1 202-555-0101', 'search_key' => 'noamlevi', 'created_by' => $fixture->id]);
        $company = Company::create(['name' => 'Carmel Supply', 'email' => $companyEmail, 'phone' => '+1 202-555-0102', 'search_key' => 'carmelsupply', 'created_by' => $fixture->id]);
        RateLimiter::clear(md5('magic-login-request'.'magic-request:email:alpha:'.hash('sha256', $email)));
        $today = now(Installation::settings()['timezone']);

        return ['email' => $email, 'owner_id' => $fixture->id, 'employee_id' => $employee->id, 'contact_id' => $contact->id, 'company_id' => $company->id, 'today' => $today->toDateString(), 'yesterday' => $today->copy()->subDay()->toDateString(), 'next' => $today->copy()->addDays(14)->toDateString(), 'manifest' => $path];
    }
    if (! $user) {
        return ['cleaned' => 0];
    }
    $assets = DB::table('assets_register')->where(fn ($q) => $q->where('created_by', $user->id)->orWhere('owner_id', $user->id))->get();
    foreach ($assets as $row) {
        if ($row->created_by !== $user->id || $row->owner_id !== $user->id || ! in_array($row->code, ['EQ-101'], true) || ! in_array($row->name, ['Office projector', 'Meeting projector'], true)) {
            throw new RuntimeException('A protected asset references this fixture; cleanup refused.');
        }
    }
    $assetIds = $assets->pluck('id');
    $employees = DB::table('crm_employee_references')->where('created_by', $user->id)->get();
    foreach ($employees as $row) {
        if ($row->name !== 'Yael Shalev' || $row->contact_id !== null || $row->user_id !== null) {
            throw new RuntimeException('A protected employee reference uses this fixture.');
        }
    }
    $contacts = DB::table('crm_contacts')->where('email', $contactEmail)->get();
    $companies = DB::table('crm_companies')->where('email', $companyEmail)->get();
    foreach ([...$contacts, ...$companies] as $row) {
        if ($row->created_by !== $user->id || ! in_array($row->name, ['Noam Levi', 'Carmel Supply'], true)) {
            throw new RuntimeException('A protected identity uses this fixture.');
        }
    }
    $relations = DB::table('crm_identity_relationships')->where(fn ($q) => $q->whereIn('contact_id', $contacts->pluck('id'))->orWhereIn('company_id', $companies->pluck('id')))->get();
    foreach ($relations as $row) {
        if ($row->created_by !== $user->id || $row->responsible_id !== $user->id || $row->main_contact_id !== null || ! in_array($row->role, ['customer', 'supplier'], true)) {
            throw new RuntimeException('A protected classification uses this fixture.');
        }
    }
    $assignments = DB::table('assets_assignments')->where(fn ($q) => $q->whereIn('asset_id', $assetIds)->orWhereIn('employee_reference_id', $employees->pluck('id'))->orWhereIn('customer_relationship_id', $relations->pluck('id')))->get();
    foreach ($assignments as $row) {
        if (! $assetIds->contains($row->asset_id) || $row->created_by !== $user->id || ($row->returned_by !== null && $row->returned_by !== $user->id) || ($row->employee_reference_id !== null && ! $employees->pluck('id')->contains($row->employee_reference_id)) || ($row->customer_relationship_id !== null && ! $relations->pluck('id')->contains($row->customer_relationship_id))) {
            throw new RuntimeException('A protected assignment uses this fixture.');
        }
    }
    $maintenance = DB::table('assets_maintenance')->where(fn ($q) => $q->whereIn('asset_id', $assetIds)->orWhere('created_by', $user->id)->orWhere('owner_id', $user->id)->orWhereIn('supplier_relationship_id', $relations->pluck('id')))->get();
    foreach ($maintenance as $row) {
        if (! $assetIds->contains($row->asset_id) || $row->created_by !== $user->id || $row->owner_id !== $user->id || $row->action !== 'Check projector lamp' || ($row->supplier_relationship_id !== null && ! $relations->pluck('id')->contains($row->supplier_relationship_id))) {
            throw new RuntimeException('A protected maintenance record uses this fixture.');
        }
    }
    $documents = DB::table('assets_documents')->whereIn('asset_id', $assetIds)->get();
    foreach ($documents as $row) {
        if ($row->created_by !== $user->id || $row->name !== 'Equipment guide' || $row->url !== 'https://documents.example.test/equipment-guide') {
            throw new RuntimeException('A protected document uses this fixture.');
        }
    }
    $history = DB::table('assets_history')->whereIn('asset_id', $assetIds)->get();
    $completions = DB::table('assets_maintenance_completions')->whereIn('maintenance_id', $maintenance->pluck('id'))->get();
    $costs = DB::table('assets_maintenance_costs')->whereIn('maintenance_id', $maintenance->pluck('id'))->get();
    $employeeHistory = DB::table('crm_employee_reference_history')->whereIn('employee_reference_id', $employees->pluck('id'))->get();
    $relationHistory = DB::table('crm_identity_relationship_history')->whereIn('relationship_id', $relations->pluck('id'))->get();
    foreach ([$history, $completions, $costs, $employeeHistory, $relationHistory] as $rows) {
        if ($rows->contains(fn ($row) => $row->actor_id !== $user->id)) {
            throw new RuntimeException('A protected historical actor uses this fixture.');
        }
    }
    $notices = DB::table('crm_work_reminders')->where('module', 'assets')->where(fn ($q) => $q->where(fn ($q) => $q->where('source', 'returns')->whereIn('record_id', $assignments->pluck('id')))->orWhere(fn ($q) => $q->where('source', 'maintenance')->whereIn('record_id', $maintenance->pluck('id'))))->get();
    if ($notices->contains(fn ($row) => $row->user_id !== $user->id)) {
        throw new RuntimeException('A protected reminder uses this fixture.');
    }
    assetsFixtureForeign('assets_register', $assetIds, ['assets_assignments', 'assets_maintenance', 'assets_documents', 'assets_history']);
    assetsFixtureForeign('assets_assignments', $assignments->pluck('id'), ['assets_history']);
    assetsFixtureForeign('assets_maintenance', $maintenance->pluck('id'), ['assets_history', 'assets_maintenance_completions', 'assets_maintenance_costs']);
    assetsFixtureForeign('crm_employee_references', $employees->pluck('id'), ['crm_employee_reference_history', 'assets_assignments']);
    assetsFixtureForeign('crm_identity_relationships', $relations->pluck('id'), ['crm_identity_relationship_history', 'assets_assignments', 'assets_maintenance']);
    assetsFixtureForeign('crm_contacts', $contacts->pluck('id'), ['crm_identity_relationships']);
    assetsFixtureForeign('crm_companies', $companies->pluck('id'), ['crm_identity_relationships']);
    $groups = ['assets_history' => $history, 'assets_documents' => $documents, 'assets_maintenance_completions' => $completions, 'assets_maintenance_costs' => $costs, 'assets_assignments' => $assignments, 'assets_maintenance' => $maintenance, 'assets_register' => $assets, 'crm_work_reminders' => $notices, 'crm_identity_relationship_history' => $relationHistory, 'crm_employee_reference_history' => $employeeHistory, 'crm_identity_relationships' => $relations, 'crm_employee_references' => $employees, 'crm_contacts' => $contacts, 'crm_companies' => $companies];
    if (collect($groups)->contains(fn ($rows) => $rows->isNotEmpty())) {
        $directory = storage_path('app/private/assets-fixture-recovery');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/'.$project.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';
        file_put_contents($path, json_encode(array_map(fn ($rows) => $rows->all(), $groups), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($path, 0600);
    }
    foreach ($groups as $table => $rows) {
        DB::table($table)->whereIn('id', $rows->pluck('id'))->delete();
    }

    return ['cleaned' => $assets->count() + $employees->count() + $contacts->count() + $companies->count()];
});
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
