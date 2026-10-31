<?php

use App\Models\User;
use Awove\Core\Installation;
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
$actual = DB::selectOne('select current_database() as name, current_user as role');
if (config('database.connections.pgsql.port') != 55438 || $actual->name !== 'base_alpha' || $actual->role !== 'base_alpha'
    || config('mail.default') !== 'smtp' || config('mail.mailers.smtp.host') !== '127.0.0.1' || config('mail.mailers.smtp.port') != 1028) {
    throw new RuntimeException('Only isolated Alpha and local Mailpit are permitted.');
}
$mode = $argv[1] ?? '';
$project = $argv[2] ?? '';
if (! in_array($mode, ['setup', 'cleanup'], true) || ! in_array($project, ['desktop', 'mobile'], true)) {
    throw new RuntimeException('Choose setup or cleanup and desktop or mobile.');
}
if ($mode === 'setup') {
    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException('Controlled fictional migration failed.');
    }
}
$email = 'employees-'.$project.'@alpha.example.test';
$user = User::where('email', $email)->first();
if ($user && $user->name !== 'Noa Cohen') {
    throw new RuntimeException('A protected account uses the reserved fixture email.');
}
$result = DB::transaction(function () use ($mode, $email, $user, $project) {
    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-employee-reference-fixtures']);
    if ($mode === 'setup') {
        Artisan::call('crm:install');
        $fixture = $user ?? User::factory()->create(['name' => 'Noa Cohen', 'email' => $email, 'email_verified_at' => now()]);
        $fixture->forceFill(['active' => true, 'display_preferences' => null])->save();
        $fixture->assignRole('Employee');
        $fixture->syncPermissions(['employees.references.view', 'employees.references.manage', 'exports.view']);
        RateLimiter::clear(md5('magic-login-request'.'magic-request:email:alpha:'.hash('sha256', $email)));
        $manifest = Installation::manifest();
        $manifest['modules'] = [];
        $manifest['capabilities']['sales'] = false;
        $path = storage_path('app/private/employee-reference-browser-manifest.json');
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($path, 0600);

        return ['email' => $email, 'manifest' => $path];
    }
    if (! $user) {
        return ['cleaned' => 0];
    }
    $records = DB::table('crm_employee_references')->where('created_by', $user->id)->get();
    foreach ($records as $record) {
        if (! in_array($record->name, ['Shira Levi', 'Shira Ben Levi'], true) || $record->contact_id !== null || $record->user_id !== null) {
            throw new RuntimeException('A protected employee record uses this fixture; cleanup refused.');
        }
    }
    $ids = $records->pluck('id');
    // Never delete a reference consumed by HR, payroll, assets, or any future module.
    $dependencies = DB::select("SELECT c.conrelid::regclass::text AS table_name, a.attname AS column_name FROM pg_constraint c JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=c.conkey[1] WHERE c.contype='f' AND c.confrelid='crm_employee_references'::regclass");
    foreach ($dependencies as $dependency) {
        if ($dependency->table_name !== 'crm_employee_reference_history' && DB::table($dependency->table_name)->whereIn($dependency->column_name, $ids)->exists()) {
            throw new RuntimeException('A protected business record references this fixture; cleanup refused.');
        }
    }
    $history = DB::table('crm_employee_reference_history')->whereIn('employee_reference_id', $ids)->get();
    if ($history->contains(fn ($row) => $row->actor_id !== $user->id)) {
        throw new RuntimeException('A protected actor references this fixture; cleanup refused.');
    }
    if ($records->isNotEmpty()) {
        $directory = storage_path('app/private/employee-reference-fixture-recovery');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/'.$project.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';
        file_put_contents($path, json_encode(['records' => $records->all(), 'history' => $history->all()], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($path, 0600);
    }
    DB::table('crm_employee_reference_history')->whereIn('employee_reference_id', $ids)->delete();
    DB::table('crm_employee_references')->whereIn('id', $ids)->delete();

    return ['cleaned' => $records->count()];
});
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
