<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Services\Routines;
use Awove\Knowledge\KnowledgeModule;
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
if (config('database.connections.pgsql.port') != 55438 || $actual->name !== 'base_alpha' || $actual->role !== 'base_alpha') {
    throw new RuntimeException('Only the isolated PostgreSQL Alpha example is permitted.');
}if (! Schema::hasTable('knowledge_entries')) {
    throw new RuntimeException('Apply the additive Knowledge migration to the isolated Alpha example first.');
}
$mode = $argv[1] ?? '';
$project = $argv[2] ?? '';
if (! in_array($mode, ['setup', 'cleanup'], true) || ! in_array($project, ['desktop', 'mobile'], true)) {
    throw new RuntimeException('Choose setup or cleanup and a known project.');
}$email = 'knowledge-'.$project.'@alpha.example.test';
$user = User::where('email', $email)->first();
if ($user && $user->name !== 'Shira Cohen') {
    throw new RuntimeException('A protected account uses the reserved fixture email.');
}
$result = DB::transaction(function () use ($mode, $project, $email, $user) {
    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-knowledge-fixtures']);
    if ($mode === 'setup') {
        Artisan::call('crm:install');
        RateLimiter::clear(md5('magic-login-request'.'magic-request:email:alpha:'.hash('sha256', $email)));
        $fixture = $user ?? User::factory()->create(['name' => 'Shira Cohen', 'email' => $email, 'email_verified_at' => now()]);
        $fixture->forceFill(['active' => true])->save();
        $fixture->assignRole('Employee');
        $fixture->syncPermissions([...(new KnowledgeModule)->permissions(), 'executive.view.own', 'executive.create', 'executive.edit.own', 'executive.archive']);
        $manifest = Installation::manifest();
        $manifest['modules'] = ['knowledge', 'executive'];
        $manifest['capabilities']['sales'] = false;
        foreach (array_keys($manifest['capabilities']) as $key) {
            if (str_starts_with($key, 'finance:')) {
                $manifest['capabilities'][$key] = false;
            }
        }$path = storage_path('app/private/knowledge-browser-manifest.json');
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($path, 0600);
        config(['core.manifest' => $path]);
        $routine = app(Routines::class)->create($fixture, 'executive', 'routines', ['name' => 'Review delivery instructions', 'owner_id' => $fixture->id, 'frequency' => 'Every working week', 'next_due_on' => now(Installation::settings()['timezone'])->toDateString(), 'checklist' => []]);

        return ['email' => $email, 'owner_id' => $fixture->id, 'routine_id' => $routine->id, 'today' => now(Installation::settings()['timezone'])->toDateString(), 'next_expiry' => now(Installation::settings()['timezone'])->addYear()->toDateString(), 'manifest' => $path];
    }
    if (! $user) {
        return ['cleaned' => 0];
    }
    $groups = [];
    foreach (['knowledge_entries' => ['Customer handover', 'Customer handover guide', 'Welcome briefing', 'Proposal introduction'], 'knowledge_renewals' => ['Professional insurance'], 'knowledge_systems' => ['Team workspace']] as $table => $names) {
        $rows = DB::table($table)->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('created_by', $user->id))->get();
        foreach ($rows as $row) {
            if ($row->owner_id !== $user->id || $row->created_by !== $user->id || ! in_array($row->title ?? $row->name, $names, true)) {
                throw new RuntimeException('A protected Knowledge record references the reserved account.');
            }
        }$groups[$table] = $rows;
    }
    $entries = $groups['knowledge_entries']->pluck('id');
    $renewals = $groups['knowledge_renewals']->pluck('id');
    $systems = $groups['knowledge_systems']->pluck('id');
    $routines = DB::table('crm_routines')->where('module', 'executive')->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('created_by', $user->id))->get();
    foreach ($routines as $row) {
        if ($row->owner_id !== $user->id || $row->created_by !== $user->id || $row->kind !== 'routines' || $row->name !== 'Review delivery instructions') {
            throw new RuntimeException('A protected routine references the reserved account.');
        }
    }$routineIds = $routines->pluck('id');
    $links = DB::table('knowledge_instructions')->where(fn ($q) => $q->whereIn('entry_id', $entries)->orWhereIn('routine_id', $routineIds))->get();
    if ($links->contains(fn ($row) => ! $entries->contains($row->entry_id) || $row->work_item_id !== null || ! $routineIds->contains($row->routine_id) || $row->created_by !== $user->id)) {
        throw new RuntimeException('A protected instruction relation references this fixture.');
    }
    $documents = DB::table('knowledge_documents')->where(fn ($q) => $q->whereIn('entry_id', $entries)->orWhereIn('renewal_id', $renewals))->get();
    foreach ($documents as $row) {
        if ($row->created_by !== $user->id || ! in_array($row->name, ['Handover instructions', 'Proposal introduction'], true) || ! str_starts_with($row->url, 'https://example.test/')) {
            throw new RuntimeException('A protected document references this fixture.');
        }
    }
    $history = DB::table('knowledge_history')->where(fn ($q) => $q->whereIn('entry_id', $entries)->orWhereIn('renewal_id', $renewals)->orWhereIn('system_id', $systems))->get();
    $events = DB::table('knowledge_renewal_events')->whereIn('renewal_id', $renewals)->get();
    $workHistory = DB::table('crm_work_history')->whereIn('routine_id', $routineIds)->get();
    $completions = DB::table('crm_routine_completions')->whereIn('routine_id', $routineIds)->get();
    foreach ([$history, $events, $workHistory] as $rows) {
        if ($rows->contains(fn ($row) => $row->actor_id !== $user->id)) {
            throw new RuntimeException('A protected actor references this fixture.');
        }
    }
    if ($completions->contains(fn ($row) => $row->completed_by !== $user->id)) {
        throw new RuntimeException('A protected completion actor references this fixture.');
    }
    $notices = DB::table('crm_work_reminders')->where(fn ($q) => $q->where(fn ($q) => $q->where('module', 'knowledge')->where('source', 'renewals')->whereIn('record_id', $renewals))->orWhere(fn ($q) => $q->where('module', 'executive')->where('source', 'routine-routines')->whereIn('record_id', $routineIds)))->get();
    if ($notices->contains(fn ($row) => $row->user_id !== $user->id)) {
        throw new RuntimeException('A protected reminder references this fixture.');
    }
    $foreign = DB::select("SELECT kcu.table_name,kcu.column_name FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON tc.constraint_name=kcu.constraint_name AND tc.constraint_schema=kcu.constraint_schema JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name=tc.constraint_name AND ccu.constraint_schema=tc.constraint_schema WHERE tc.constraint_type='FOREIGN KEY' AND tc.table_schema='public' AND ccu.table_name='crm_routines'");
    foreach ($foreign as $fk) {
        if (! in_array($fk->table_name, ['knowledge_instructions', 'crm_work_history', 'crm_routine_completions'], true) && DB::table($fk->table_name)->whereIn($fk->column_name, $routineIds)->exists()) {
            throw new RuntimeException('A protected module references this routine fixture.');
        }
    }
    $snapshot = ['crm_work_reminders' => $notices, 'knowledge_instructions' => $links, 'knowledge_documents' => $documents, 'knowledge_history' => $history, 'knowledge_renewal_events' => $events, 'crm_work_history' => $workHistory, 'crm_routine_completions' => $completions, 'crm_routines' => $routines, ...$groups];
    if ($entries->isNotEmpty() || $routines->isNotEmpty()) {
        $directory = storage_path('app/private/knowledge-fixture-recovery');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }$path = $directory.'/'.$project.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';
        file_put_contents($path, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($path, 0600);
    }foreach ($snapshot as $table => $rows) {
        DB::table($table)->whereIn('id', $rows->pluck('id'))->delete();
    }

    return ['cleaned' => $entries->count() + $renewals->count() + $systems->count() + $routines->count()];
});
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
