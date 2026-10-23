<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/fictional-fixtures.php';
$app = require __DIR__.'/../bootstrap/app.php';
$installation = $argv[1] ?? 'alpha';
if (! in_array($installation, ['alpha', 'beta'], true)) {
    throw new RuntimeException('Local examples only.');
}
$app->loadEnvironmentFrom('.env.'.$installation);
$app->make(Kernel::class)->bootstrap();
FictionalCrmFixtures::assertLocalExample($installation);

/** Fingerprint every original field except explicit planned display-name corrections. */
function fixtureSnapshot(array $plan): array
{
    $snapshot = [];
    foreach (['users', 'crm_companies', 'crm_contacts', 'crm_leads', 'crm_tasks', 'crm_notes', 'crm_activity', 'crm_reminders', 'service_details_leads'] as $table) {
        if (! Schema::hasTable($table)) {
            continue;
        }
        $snapshot[$table] = [];
        foreach (DB::table($table)->orderBy('id')->get() as $row) {
            $attributes = array_diff_key((array) $row, $plan[$table][$row->id] ?? []);
            $snapshot[$table][$row->id] = hash('sha256', json_encode($attributes));
        }
    }

    return $snapshot;
}

$result = DB::transaction(function () use ($installation) {
    $names = [
        'reviewer@'.$installation.'.example.test' => FictionalCrmFixtures::reviewerName($installation),
        'table-desktop@'.$installation.'.example.test' => FictionalCrmFixtures::tableReviewerName('desktop'),
        'table-mobile@'.$installation.'.example.test' => FictionalCrmFixtures::tableReviewerName('mobile'),
        'owner@'.$installation.'.example.test' => $installation === 'alpha' ? 'Avraham Levi' : 'Sarah Cohen',
    ];
    $users = User::whereIn('email', array_keys($names))->get();
    $ids = $users->pluck('id');
    $plan = [];
    foreach ($users as $user) {
        if ($user->name !== $names[$user->email]) {
            $plan['users'][$user->id] = ['name' => $names[$user->email]];
        }
    }
    foreach (['crm_companies' => 'companies', 'crm_contacts' => 'contacts'] as $table => $kind) {
        foreach (DB::table($table)->whereIn('created_by', $ids)->get(['id', 'name']) as $row) {
            $name = FictionalCrmFixtures::englishName($row->name, $kind);
            if ($name !== null && $name !== $row->name) {
                $plan[$table][$row->id] = ['name' => $name, 'search_key' => FictionalCrmFixtures::searchKey($name)];
            } elseif (preg_match('/\p{Hebrew}/u', $row->name)) {
                throw new RuntimeException('Unrecognized Hebrew demo name: '.$table.' #'.$row->id);
            }
        }
    }
    foreach (DB::table('crm_leads')->whereIn('created_by', $ids)->get(['id', 'title']) as $lead) {
        if (preg_match('/^פניית בדיקת עריכה ((?:desktop|mobile)-\d{13})$/u', $lead->title, $match)) {
            $plan['crm_leads'][$lead->id] = ['title' => 'Contact edit enquiry '.$match[1]];
        }
    }
    $taskTitles = [];
    foreach (FictionalCrmFixtures::catalog() as $entry) {
        foreach (FictionalCrmFixtures::companyAliases($entry) as $alias) {
            $taskTitles['Check in with '.$alias] = 'Check in with '.$entry['company'];
        }
    }
    foreach (DB::table('crm_tasks')->join('crm_leads', 'crm_leads.id', '=', 'crm_tasks.lead_id')->whereIn('crm_leads.created_by', $ids)->whereColumn('crm_tasks.assignee_id', 'crm_leads.created_by')->get(['crm_tasks.id', 'crm_tasks.title']) as $task) {
        if (isset($taskTitles[$task->title]) && $taskTitles[$task->title] !== $task->title) {
            $plan['crm_tasks'][$task->id] = ['title' => $taskTitles[$task->title]];
        }
    }

    $before = fixtureSnapshot($plan);
    foreach ($plan as $table => $rows) {
        foreach ($rows as $id => $attributes) {
            // Query-builder update changes only these columns, including no timestamp writes.
            DB::table($table)->where('id', $id)->update($attributes);
        }
    }
    if ($before !== fixtureSnapshot($plan)) {
        throw new RuntimeException('A protected field, original record or relationship changed.');
    }

    $remaining = 0;
    foreach (['users' => 'name', 'crm_companies' => 'name', 'crm_contacts' => 'name', 'crm_leads' => 'title'] as $table => $column) {
        $query = DB::table($table);
        $table === 'users' ? $query->whereIn('id', $ids) : $query->whereIn('created_by', $ids);
        foreach ($query->pluck($column) as $value) {
            $remaining += preg_match('/\p{Hebrew}/u', $value);
        }
    }
    foreach (DB::table('crm_tasks')->join('crm_leads', 'crm_leads.id', '=', 'crm_tasks.lead_id')->whereIn('crm_leads.created_by', $ids)->pluck('crm_tasks.title') as $title) {
        $remaining += preg_match('/\p{Hebrew}/u', $title);
    }
    if ($remaining !== 0) {
        throw new RuntimeException('An unrecognized current demo display name/title still contains Hebrew script.');
    }

    return [
        'installation' => $installation,
        'renamed_rows' => array_map('count', $plan),
        'remaining_hebrew_display_names_and_titles' => $remaining,
        'original_ids_relationships_emails_phones_authentication_timestamps_notes_tasks_history_preserved' => true,
    ];
});
echo json_encode($result, JSON_PRETTY_PRINT)."\n";
