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

/** Keep protected original rows in memory only; no credentials or customer data output. */
function fixtureSnapshot(array $mutableTaskIds = []): array
{
    $ignored = [
        'users' => ['name'],
        'crm_companies' => ['name', 'email', 'phone', 'search_key'],
        'crm_contacts' => ['name', 'email', 'phone', 'search_key'],
        'crm_leads' => ['contact_id'],
        'crm_tasks' => [],
        'crm_notes' => [],
        'crm_activity' => [],
        'crm_reminders' => [],
        'service_details_leads' => [],
    ];
    $snapshot = [];
    foreach ($ignored as $table => $columns) {
        if (! Schema::hasTable($table)) {
            continue;
        }
        foreach (DB::table($table)->orderBy('id')->get() as $row) {
            $attributes = array_diff_key((array) $row, array_flip($columns));
            if ($table === 'crm_tasks' && in_array($row->id, $mutableTaskIds, true)) {
                unset($attributes['title']);
            }
            $snapshot[$table][$row->id] = hash('sha256', json_encode($attributes));
        }
    }

    return $snapshot;
}

$result = DB::transaction(function () use ($installation) {
    $demoUsers = User::whereIn('email', ['reviewer@'.$installation.'.example.test', 'table-desktop@'.$installation.'.example.test', 'table-mobile@'.$installation.'.example.test'])->pluck('id');
    $oldTitles = array_map(fn ($entry) => 'Check in with '.$entry['old_company'], FictionalCrmFixtures::catalog());
    $mutableTaskIds = DB::table('crm_tasks')->join('crm_leads', 'crm_leads.id', '=', 'crm_tasks.lead_id')->whereIn('crm_leads.created_by', $demoUsers)->whereColumn('crm_tasks.assignee_id', 'crm_leads.created_by')->whereIn('crm_tasks.title', $oldTitles)->pluck('crm_tasks.id')->all();
    $before = fixtureSnapshot($mutableTaskIds);
    $contactsBefore = DB::table('crm_leads')->whereNotNull('contact_id')->orderBy('id')->pluck('contact_id', 'id')->all();
    $result = ['installation' => $installation, 'accounts' => 0, 'task_titles' => 0, 'catalog_records' => [], 'browser_records' => ['companies' => 0, 'contacts' => 0]];
    $names = [
        'reviewer@'.$installation.'.example.test' => FictionalCrmFixtures::reviewerName($installation),
        'table-desktop@'.$installation.'.example.test' => FictionalCrmFixtures::tableReviewerName('desktop'),
        'table-mobile@'.$installation.'.example.test' => FictionalCrmFixtures::tableReviewerName('mobile'),
        'owner@'.$installation.'.example.test' => $installation === 'alpha' ? 'אברהם לוי' : 'שרה כהן',
    ];
    foreach ($names as $email => $name) {
        $user = User::where('email', $email)->first();
        if (! $user) {
            continue;
        }
        FictionalCrmFixtures::enrich($user, ['name' => $name]);
        $result['accounts']++;
        if (str_starts_with($email, 'owner@')) {
            continue;
        }
        $result['task_titles'] += FictionalCrmFixtures::localizeTaskTitles($user);
        $result['catalog_records'][] = FictionalCrmFixtures::sync($user, $installation, str_starts_with($email, 'table-'), false);
        if (str_starts_with($email, 'reviewer@')) {
            $result['browser_records'] = FictionalCrmFixtures::localizeBrowserRows($user);
        }
    }
    $after = fixtureSnapshot($mutableTaskIds);
    foreach ($before as $table => $rows) {
        foreach ($rows as $id => $fingerprint) {
            if (($after[$table][$id] ?? null) !== $fingerprint) {
                throw new RuntimeException('Protected original record changed: '.$table.' #'.$id);
            }
        }
    }
    $contactsAfter = DB::table('crm_leads')->whereIn('id', array_keys($contactsBefore))->orderBy('id')->pluck('contact_id', 'id')->all();
    if ($contactsBefore !== $contactsAfter) {
        throw new RuntimeException('An existing lead/contact relationship changed.');
    }
    $result['original_ids_relationships_authentication_notes_tasks_history_preserved'] = true;

    return $result;
});
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
