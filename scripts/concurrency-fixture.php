<?php

use App\Models\User;
use Awove\Core\Jobs\DeliverReminder;
use Awove\Core\Models\Company;
use Awove\Core\Models\Reminder;
use Awove\Core\Models\Task;
use Awove\Core\Services\Leads;
use Awove\Core\Services\Tasks;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->isLocal() || config('database.connections.pgsql.database') !== 'base_alpha') {
    throw new RuntimeException('Synthetic local Alpha only.');
}
if (($argv[1] ?? '') === 'setup') {
    $u = User::where('email', 'reviewer@alpha.example.test')->firstOrFail();
    $company = Company::where('created_by', $u->id)->firstOrFail();
    $lead = app(Leads::class)->create($u, ['title' => 'Synthetic overlapping-worker probe', 'company_id' => $company->id, 'owner_id' => $u->id, 'status_id' => 'new']);
    $task = $lead->tasks()->create(['title' => 'Synthetic deduplication check', 'assignee_id' => $u->id, 'due_at' => now()->addHour(), 'remind_at' => now()->subMinute(), 'priority' => 'normal']);
    for ($i = 0; $i < 20; $i++) {
        DeliverReminder::dispatch($task->id, 1);
    }
    echo $task->id;
} else {
    $task = Task::findOrFail((int) $argv[2]);
    $count = Reminder::where('task_id', $task->id)->count();
    if ($count !== 1) {
        throw new RuntimeException('Expected one reminder, found '.$count);
    }
    app(Tasks::class)->update($task->assignee, $task, ['status' => 'completed', 'version' => 1]);
    $task->lead()->update(['archived_at' => now()]);
    echo json_encode(['workers' => 2, 'duplicate_jobs' => 20, 'delivered_reminders' => $count, 'passed' => true], JSON_PRETTY_PRINT);
}
