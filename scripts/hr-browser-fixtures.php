<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Services\EmployeeReferences;
use Awove\Hr\HrModule;
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
if (config('database.connections.pgsql.port') != 55438 || $actual->name !== 'base_alpha' || $actual->role !== 'base_alpha') {
    throw new RuntimeException('Only the isolated PostgreSQL Alpha example is permitted.');
}
$mode = $argv[1] ?? '';
$project = $argv[2] ?? '';
if (! in_array($mode, ['setup', 'cleanup'], true) || ! in_array($project, ['desktop', 'mobile'], true)) {
    throw new RuntimeException('Choose setup or cleanup and desktop or mobile.');
}
$email = 'hr-'.$project.'@alpha.example.test';
$user = User::where('email', $email)->first();
if ($user && $user->name !== 'Shira Cohen') {
    throw new RuntimeException('A protected account uses the reserved fixture email.');
}
$result = DB::transaction(function () use ($mode, $project, $email, $user) {
    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-hr-fixtures']);
    if ($mode === 'setup') {
        Artisan::call('crm:install');
        RateLimiter::clear(md5('magic-login-request'.'magic-request:email:alpha:'.hash('sha256', $email)));
        $fixture = $user ?? User::factory()->create(['name' => 'Shira Cohen', 'email' => $email, 'email_verified_at' => now()]);
        $fixture->forceFill(['active' => true])->save();
        $fixture->assignRole('Employee');
        $fixture->syncPermissions([...(new HrModule)->permissions(), 'employees.references.view', 'employees.references.manage']);
        $references = [];
        foreach (['Yael Shalev', 'Uri Levi'] as $name) {
            $record = DB::table('crm_employee_references')->where('created_by', $fixture->id)->where('name', $name)->first();
            if ($record && ($record->contact_id !== null || $record->user_id !== null)) {
                throw new RuntimeException('A protected employee link uses this fixture.');
            }$references[$name] = $record?->id ?? app(EmployeeReferences::class)->create($fixture, ['name' => $name])->id;
        }
        $manifest = Installation::manifest();
        $manifest['modules'] = ['hr'];
        $manifest['capabilities']['sales'] = false;
        foreach (array_keys($manifest['capabilities']) as $key) {
            if (str_starts_with($key, 'finance:')) {
                $manifest['capabilities'][$key] = false;
            }
        }
        $path = storage_path('app/private/hr-browser-manifest.json');
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($path, 0600);

        return ['email' => $email, 'owner_id' => $fixture->id, 'employee_id' => $references['Yael Shalev'], 'manager_id' => $references['Uri Levi'], 'today' => now(Installation::settings()['timezone'])->toDateString(), 'manifest' => $path];
    }
    if (! $user) {
        return ['cleaned' => 0];
    }
    $employees = DB::table('crm_employee_references')->where('created_by', $user->id)->get();
    foreach ($employees as $row) {
        if (! in_array($row->name, ['Yael Shalev', 'Uri Levi'], true) || $row->contact_id !== null || $row->user_id !== null) {
            throw new RuntimeException('A protected employee reference uses this fixture.');
        }
    }$employeeIds = $employees->pluck('id');
    $profiles = DB::table('hr_employment_profiles')->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('created_by', $user->id)->orWhereIn('employee_reference_id', $employeeIds)->orWhereIn('manager_employee_reference_id', $employeeIds))->get();
    $vacancies = DB::table('hr_vacancies')->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('created_by', $user->id))->get();
    $vacancyIds = $vacancies->pluck('id');
    $candidates = DB::table('hr_candidates')->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('created_by', $user->id)->orWhereIn('vacancy_id', $vacancyIds)->orWhereIn('employee_reference_id', $employeeIds))->get();
    $cases = DB::table('hr_cases')->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('created_by', $user->id)->orWhereIn('employee_reference_id', $employeeIds))->get();
    $items = DB::table('crm_work_items')->where('module', 'hr')->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('created_by', $user->id))->get();
    foreach (['profiles' => $profiles, 'vacancies' => $vacancies, 'candidates' => $candidates, 'cases' => $cases, 'items' => $items] as $kind => $records) {
        foreach ($records as $row) {
            $allowed = match ($kind) {
                'profiles' => ['Project coordinator', 'Senior project coordinator'],'vacancies' => ['Operations coordinator'],'candidates' => ['Tamar Erez'],'cases' => ['Prepare first working day', 'Prepare final handover'],'items' => ['Review required documents', 'Return office equipment']
            };
            $label = $kind === 'profiles' || $kind === 'vacancies' ? $row->role_name : ($row->title ?? $row->name);
            if ($row->owner_id !== $user->id || $row->created_by !== $user->id || ! in_array($label, $allowed, true)) {
                throw new RuntimeException('A protected HR record references the reserved account.');
            }
            if (isset($row->employee_reference_id) && ! $employeeIds->contains($row->employee_reference_id)) {
                throw new RuntimeException('A protected employee is linked to this fixture.');
            }
            if (isset($row->manager_employee_reference_id) && ! $employeeIds->contains($row->manager_employee_reference_id)) {
                throw new RuntimeException('A protected manager is linked to this fixture.');
            }
            if ($kind === 'candidates' && ! $vacancyIds->contains($row->vacancy_id)) {
                throw new RuntimeException('A protected vacancy is linked to this fixture.');
            }
            if ($kind === 'items' && $row->kind !== 'checklist') {
                throw new RuntimeException('A protected work type uses the reserved account.');
            }
        }
    }
    $profileIds = $profiles->pluck('id');
    $candidateIds = $candidates->pluck('id');
    $caseIds = $cases->pluck('id');
    $itemIds = $items->pluck('id');
    $links = DB::table('hr_case_items')->where(fn ($q) => $q->whereIn('case_id', $caseIds)->orWhereIn('work_item_id', $itemIds))->get();
    if ($links->contains(fn ($row) => ! $caseIds->contains($row->case_id) || ! $itemIds->contains($row->work_item_id))) {
        throw new RuntimeException('A protected checklist relation references this fixture.');
    }
    $documents = DB::table('hr_documents')->where(fn ($q) => $q->whereIn('employment_id', $profileIds)->orWhereIn('candidate_id', $candidateIds)->orWhereIn('case_id', $caseIds))->get();
    if ($documents->contains(fn ($row) => $row->created_by !== $user->id || ! in_array($row->name, ['Candidate CV', 'Signed employment policy'], true) || ! str_starts_with($row->url, 'https://example.test/'))) {
        throw new RuntimeException('A protected document references this fixture.');
    }
    $history = DB::table('hr_history')->where(fn ($q) => $q->whereIn('employment_id', $profileIds)->orWhereIn('vacancy_id', $vacancyIds)->orWhereIn('candidate_id', $candidateIds)->orWhereIn('case_id', $caseIds))->get();
    $workHistory = DB::table('crm_work_history')->whereIn('work_item_id', $itemIds)->get();
    $employeeHistory = DB::table('crm_employee_reference_history')->whereIn('employee_reference_id', $employeeIds)->get();
    foreach ([$history, $workHistory, $employeeHistory] as $rows) {
        if ($rows->contains(fn ($row) => $row->actor_id !== $user->id)) {
            throw new RuntimeException('A protected actor references this fixture.');
        }
    }
    $notices = DB::table('crm_work_reminders')->where('module', 'hr')->where(fn ($q) => $q->where(fn ($q) => $q->where('source', 'cases')->whereIn('record_id', $caseIds))->orWhere(fn ($q) => $q->where('source', 'candidates')->whereIn('record_id', $candidateIds))->orWhere(fn ($q) => $q->where('source', 'work-checklist')->whereIn('record_id', $itemIds)))->get();
    if ($notices->contains(fn ($row) => $row->user_id !== $user->id)) {
        throw new RuntimeException('A protected reminder references this fixture.');
    }
    $foreign = DB::select("SELECT kcu.table_name,kcu.column_name FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON tc.constraint_name=kcu.constraint_name AND tc.constraint_schema=kcu.constraint_schema JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name=tc.constraint_name AND ccu.constraint_schema=tc.constraint_schema WHERE tc.constraint_type='FOREIGN KEY' AND tc.table_schema='public' AND ccu.table_name='crm_employee_references'");
    foreach ($foreign as $fk) {
        if (! in_array($fk->table_name, ['hr_employment_profiles', 'hr_candidates', 'hr_cases', 'crm_employee_reference_history'], true) && DB::table($fk->table_name)->whereIn($fk->column_name, $employeeIds)->exists()) {
            throw new RuntimeException('A protected module references this employee fixture.');
        }
    }
    $snapshot = ['hr_documents' => $documents->all(), 'hr_history' => $history->all(), 'hr_case_items' => $links->all(), 'crm_work_history' => $workHistory->all(), 'crm_work_items' => $items->all(), 'hr_cases' => $cases->all(), 'hr_candidates' => $candidates->all(), 'hr_vacancies' => $vacancies->all(), 'hr_employment_profiles' => $profiles->all(), 'crm_employee_reference_history' => $employeeHistory->all(), 'crm_employee_references' => $employees->all(), 'crm_work_reminders' => $notices->all()];
    if ($employees->isNotEmpty()) {
        $directory = storage_path('app/private/hr-fixture-recovery');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }$path = $directory.'/'.$project.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';
        file_put_contents($path, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($path, 0600);
    }
    foreach (['crm_work_reminders' => $notices, 'hr_documents' => $documents, 'hr_history' => $history, 'hr_case_items' => $links, 'crm_work_history' => $workHistory, 'crm_work_items' => $items, 'hr_cases' => $cases, 'hr_candidates' => $candidates, 'hr_vacancies' => $vacancies, 'hr_employment_profiles' => $profiles, 'crm_employee_reference_history' => $employeeHistory, 'crm_employee_references' => $employees] as $table => $rows) {
        DB::table($table)->whereIn('id', $rows->pluck('id'))->delete();
    }

    return ['cleaned' => $profiles->count() + $candidates->count() + $cases->count() + $items->count()];
});
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
