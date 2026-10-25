<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Services\EmployeeReferences;
use Awove\Finance\Payroll\PayrollCapability;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

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
    throw new RuntimeException('Choose setup or cleanup and desktop or mobile.');
}
if ($mode === 'setup' && Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException('Controlled fictional migration failed.');
}
$email = 'payroll-'.$project.'@alpha.example.test';
$user = User::where('email', $email)->first();
if ($user && $user->name !== 'Noa Cohen') {
    throw new RuntimeException('A protected account uses the reserved fixture email.');
}
$result = DB::transaction(function () use ($mode, $project, $email, $user) {
    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-payroll-fixtures']);
    if ($mode === 'setup') {
        Artisan::call('crm:install');
        $fixture = $user ?? User::factory()->create(['name' => 'Noa Cohen', 'email' => $email, 'email_verified_at' => now()]);
        $fixture->forceFill(['active' => true, 'display_preferences' => null])->save();
        $fixture->assignRole('Employee');
        $permissions = array_values(array_diff((new PayrollCapability)->permissions(), ['finance.payroll.view.all', 'finance.payroll.edit.all', 'finance.payroll.assign']));
        $fixture->syncPermissions([...$permissions, 'employees.references.view', 'employees.references.manage']);
        $employee = app(EmployeeReferences::class)->create($fixture, ['name' => 'Yael Shalev', 'active' => false]);
        RateLimiter::clear(md5('magic-login-request'.'magic-request:email:alpha:'.hash('sha256', $email)));
        $manifest = Installation::manifest();
        $manifest['modules'] = ['finance'];
        $manifest['capabilities'] = ['sales' => false, 'finance:collections' => false, 'finance:expenses' => false, 'finance:payroll' => true];
        $manifest['payroll_inputs'] = [['key' => 'hours', 'label' => 'Reported hours', 'type' => 'decimal', 'unit' => 'hours', 'scale' => 4, 'required' => true], ['key' => 'expenses', 'label' => 'Agreed expenses', 'type' => 'money', 'unit' => $manifest['currency'], 'scale' => 2, 'required' => false], ['key' => 'review_note', 'label' => 'Review note', 'type' => 'text', 'unit' => '', 'scale' => 2, 'required' => true]];
        $path = storage_path('app/private/payroll-browser-manifest.json');
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($path, 0600);

        return ['email' => $email, 'employee_id' => $employee->id, 'manifest' => $path, 'today' => now(Installation::settings()['timezone'])->toDateString()];
    }
    if (! $user) {
        return ['cleaned' => 0];
    }
    $employees = DB::table('crm_employee_references')->where('created_by', $user->id)->get();
    $employeeIds = $employees->pluck('id');
    foreach ($employees as $employee) {
        if ($employee->name !== 'Yael Shalev' || $employee->contact_id !== null || $employee->user_id !== null) {
            throw new RuntimeException('A protected employee record references this fixture; cleanup refused.');
        }
    }
    $records = DB::table('finance_payroll_preparations')->where(fn ($q) => $q->where('created_by', $user->id)->orWhere('owner_id', $user->id)->orWhereIn('employee_reference_id', $employeeIds))->get();
    foreach ($records as $record) {
        if ($record->created_by !== $user->id || $record->owner_id !== $user->id || ! $employeeIds->contains($record->employee_reference_id) || $record->period_start_on !== '2026-10-01' || $record->period_end_on !== '2026-10-31') {
            throw new RuntimeException('A protected payroll preparation references this fixture; cleanup refused.');
        }
    }
    $ids = $records->pluck('id');
    $documents = DB::table('finance_payroll_documents')->whereIn('preparation_id', $ids)->get();
    $history = DB::table('finance_payroll_history')->whereIn('preparation_id', $ids)->get();
    $employeeHistory = DB::table('crm_employee_reference_history')->whereIn('employee_reference_id', $employeeIds)->get();
    if ($documents->contains(fn ($d) => $d->added_by !== $user->id || $d->label !== 'Reviewed input summary' || $d->url !== 'https://documents.example.test/payroll-review') || $history->contains(fn ($h) => $h->actor_id !== $user->id) || $employeeHistory->contains(fn ($h) => $h->actor_id !== $user->id)) {
        throw new RuntimeException('A protected document or actor references this fixture; cleanup refused.');
    }
    $dependencies = DB::select("SELECT c.conrelid::regclass::text AS table_name,a.attname AS column_name FROM pg_constraint c JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=c.conkey[1] WHERE c.contype='f' AND c.confrelid='crm_employee_references'::regclass");
    foreach ($dependencies as $dependency) {
        if (! in_array($dependency->table_name, ['crm_employee_reference_history', 'finance_payroll_preparations'], true) && DB::table($dependency->table_name)->whereIn($dependency->column_name, $employeeIds)->exists()) {
            throw new RuntimeException('Another business module references this fixture; cleanup refused.');
        }
    }
    if ($employees->isNotEmpty() || $records->isNotEmpty()) {
        $directory = storage_path('app/private/payroll-fixture-recovery');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }$path = $directory.'/'.$project.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';
        file_put_contents($path, json_encode(['employees' => $employees->all(), 'employee_history' => $employeeHistory->all(), 'preparations' => $records->all(), 'documents' => $documents->all(), 'history' => $history->all()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($path, 0600);
    }
    DB::table('finance_payroll_documents')->whereIn('id', $documents->pluck('id'))->delete();
    DB::table('finance_payroll_history')->whereIn('id', $history->pluck('id'))->delete();
    DB::table('finance_payroll_preparations')->whereIn('id', $ids)->delete();
    DB::table('crm_employee_reference_history')->whereIn('id', $employeeHistory->pluck('id'))->delete();
    DB::table('crm_employee_references')->whereIn('id', $employeeIds)->delete();

    return ['cleaned' => $records->count() + $employees->count()];
});
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
