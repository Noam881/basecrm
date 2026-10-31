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
if (config('database.connections.pgsql.port') != 55438 || $actual->name !== 'base_alpha' || $actual->role !== 'base_alpha') {
    throw new RuntimeException('Only the isolated PostgreSQL Alpha example is permitted.');
}
$mode = $argv[1] ?? '';
$project = $argv[2] ?? '';
if (! in_array($mode, ['setup', 'cleanup'], true) || ! in_array($project, ['desktop', 'mobile'], true)) {
    throw new RuntimeException('Choose setup or cleanup and desktop or mobile.');
}
$email = 'executive-'.$project.'@alpha.example.test';
$user = User::where('email', $email)->first();
if ($user && $user->name !== 'Shira Levi') {
    throw new RuntimeException('A protected account uses the reserved fixture email.');
}
$result = DB::transaction(function () use ($mode, $project, $email, $user) {
    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-executive-fixtures']);
    if ($mode === 'setup') {
        RateLimiter::clear(md5('magic-login-request'.'magic-request:email:alpha:'.hash('sha256', $email)));
        Artisan::call('crm:install');
        $fixture = $user ?? User::factory()->create(['name' => 'Shira Levi', 'email' => $email, 'email_verified_at' => now()]);
        $fixture->forceFill(['active' => true])->save();
        $fixture->assignRole('Employee');
        $fixture->syncPermissions(['executive.view.own', 'executive.create', 'executive.edit.own', 'executive.archive', 'executive.export']);
        $manifest = Installation::manifest();
        $manifest['modules'] = ['executive'];
        $manifest['capabilities']['sales'] = false;
        $path = storage_path('app/private/executive-browser-manifest.json');
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($path, 0600);
        $today = now(Installation::settings()['timezone']);

        return ['email' => $email, 'owner_id' => $fixture->id, 'today' => $today->toDateString(), 'next' => $today->copy()->addDays(7)->toDateString(), 'manifest' => $path];
    }
    if (! $user) {
        return ['cleaned' => 0];
    }
    $goals = DB::table('executive_goals')->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('created_by', $user->id))->get();
    $tasks = DB::table('crm_work_items')->where('module', 'executive')->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('created_by', $user->id))->get();
    $routines = DB::table('crm_routines')->where('module', 'executive')->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('created_by', $user->id))->get();
    foreach (['goals' => $goals, 'tasks' => $tasks, 'routines' => $routines] as $kind => $records) {
        $allowed = match ($kind) {
            'goals' => ['Improve project delivery'],
            'tasks' => ['Review project progress', 'Prepare review agenda'],
            default => ['Weekly management review'],
        };
        foreach ($records as $record) {
            if ($record->owner_id !== $user->id || $record->created_by !== $user->id || ! in_array($record->title ?? $record->name, $allowed, true) || ($kind !== 'goals' && $record->kind !== ($kind === 'tasks' ? 'tasks' : 'routines'))) {
                throw new RuntimeException('A protected Executive record references this fixture; cleanup refused.');
            }
        }
    }
    $taskIds = $tasks->pluck('id');
    $routineIds = $routines->pluck('id');
    $goalIds = $goals->pluck('id');
    $links = DB::table('executive_task_goals')->where(fn ($q) => $q->whereIn('work_item_id', $taskIds)->orWhereIn('goal_id', $goalIds))->get();
    if ($links->contains(fn ($row) => ! $taskIds->contains($row->work_item_id) || ! $goalIds->contains($row->goal_id))) {
        throw new RuntimeException('A protected task goal relationship references this fixture; cleanup refused.');
    }
    $linkHistory = DB::table('executive_task_goal_history')->where(fn ($q) => $q->whereIn('work_item_id', $taskIds)->orWhereIn('from_goal_id', $goalIds)->orWhereIn('to_goal_id', $goalIds))->get();
    if ($linkHistory->contains(fn ($row) => $row->actor_id !== $user->id || ! $taskIds->contains($row->work_item_id) || ($row->from_goal_id && ! $goalIds->contains($row->from_goal_id)) || ($row->to_goal_id && ! $goalIds->contains($row->to_goal_id)))) {
        throw new RuntimeException('A protected goal history relationship references this fixture; cleanup refused.');
    }
    $goalHistory = DB::table('executive_goal_history')->whereIn('goal_id', $goalIds)->get();
    $history = DB::table('crm_work_history')->where(fn ($q) => $q->whereIn('work_item_id', $taskIds)->orWhereIn('routine_id', $routineIds))->get();
    $completions = DB::table('crm_routine_completions')->whereIn('routine_id', $routineIds)->get();
    if ($goalHistory->contains(fn ($row) => $row->actor_id !== $user->id) || $history->contains(fn ($row) => $row->actor_id !== $user->id) || $completions->contains(fn ($row) => $row->completed_by !== $user->id)) {
        throw new RuntimeException('A protected history actor references this fixture; cleanup refused.');
    }
    $notices = DB::table('crm_work_reminders')->where('module', 'executive')->where(function ($q) use ($taskIds, $goalIds, $routineIds) {
        $q->where(fn ($q) => $q->where('source', 'goals')->whereIn('record_id', $goalIds))->orWhere(fn ($q) => $q->where('source', 'work-tasks')->whereIn('record_id', $taskIds))->orWhere(fn ($q) => $q->where('source', 'routine-routines')->whereIn('record_id', $routineIds));
    })->get();
    if ($notices->contains(fn ($row) => $row->user_id !== $user->id)) {
        throw new RuntimeException('A protected reminder references this fixture; cleanup refused.');
    }
    $snapshot = ['executive_goals' => $goals->all(), 'crm_work_items' => $tasks->all(), 'crm_routines' => $routines->all(), 'executive_task_goals' => $links->all(), 'executive_task_goal_history' => $linkHistory->all(), 'executive_goal_history' => $goalHistory->all(), 'crm_work_history' => $history->all(), 'crm_routine_completions' => $completions->all(), 'crm_work_reminders' => $notices->all()];
    if ($goals->isNotEmpty() || $tasks->isNotEmpty() || $routines->isNotEmpty()) {
        $directory = storage_path('app/private/executive-fixture-recovery');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/'.$project.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';
        file_put_contents($path, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($path, 0600);
    }
    foreach (['crm_work_reminders' => $notices, 'executive_task_goal_history' => $linkHistory, 'executive_task_goals' => $links, 'executive_goal_history' => $goalHistory, 'crm_work_history' => $history, 'crm_routine_completions' => $completions, 'crm_work_items' => $tasks, 'crm_routines' => $routines, 'executive_goals' => $goals] as $table => $records) {
        DB::table($table)->whereIn('id', $records->pluck('id'))->delete();
    }

    return ['cleaned' => $goals->count() + $tasks->count() + $routines->count()];
});
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
