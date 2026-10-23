<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/fictional-fixtures.php';
require __DIR__.'/browser-fixtures.php';
$app = require __DIR__.'/../bootstrap/app.php';
$installation = $argv[1] ?? '';
if (! in_array($installation, ['alpha', 'beta'], true)) {
    throw new RuntimeException('Choose the isolated local alpha or beta example.');
}
$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (in_array($argument, ['--apply', '--legacy'], true)) {
        $options[substr($argument, 2)] = true;
    } elseif (preg_match('/^--(project|restore)=(.+)$/', $argument, $match)) {
        $options[$match[1]] = $match[2];
    } else {
        throw new RuntimeException('Unsupported cleanup option.');
    }
}
$app->loadEnvironmentFrom('.env.'.$installation);
$app->make(Kernel::class)->bootstrap();
FictionalCrmFixtures::assertLocalExample($installation);
$tables = ['crm_contact_notes', 'crm_reminders', 'service_details_leads', 'crm_notes', 'crm_activity', 'crm_tasks', 'crm_leads', 'crm_contacts', 'crm_companies'];
$tables = array_values(array_filter($tables, fn ($table) => Schema::hasTable($table)));
$directory = storage_path('app/private/browser-fixture-recovery');

/** Compare every retained CRM field and every account without printing their contents. */
function protectedBrowserRows(array $tables, array $excluded): array
{
    $fingerprints = [];
    foreach (['users', ...$tables] as $table) {
        foreach (DB::table($table)->whereNotIn('id', $excluded[$table] ?? [])->orderBy('id')->get() as $row) {
            $fingerprints[$table][$row->id] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
        }
    }

    return $fingerprints;
}

/** Recovery copies are private, ignored local data and contain only selected synthetic rows. */
function saveBrowserRecovery(string $directory, string $installation, array $rows): string
{
    $previousMask = umask(0077);
    try {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Could not create the private recovery directory.');
        }
        $file = $directory.'/'.$installation.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';
        $snapshot = ['version' => 1, 'installation' => $installation, 'rows' => $rows, 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
        if (file_put_contents($file, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX) === false || ! chmod($file, 0600)) {
            throw new RuntimeException('Could not save the private recovery copy.');
        }

        return $file;
    } finally {
        umask($previousMask);
    }
}

$result = DB::transaction(function () use ($installation, $options, $tables, $directory) {
    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-browser-fixture-cleanup']);
    // A plan call verifies the effective connection as well as the configured installation.
    $project = $options['project'] ?? null;
    $legacy = $options['legacy'] ?? false;
    if (isset($options['restore'])) {
        if ($project !== null || $legacy) {
            throw new RuntimeException('Restore cannot be combined with a cleanup selection.');
        }
        BrowserFixtures::plan($installation, null, true);
        $file = realpath($options['restore']);
        $root = realpath($directory);
        if (! $root || ! $file || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Restore requires a private local recovery copy.');
        }
        $snapshot = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $rows = $snapshot['rows'] ?? [];
        if (($snapshot['version'] ?? null) !== 1 || ($snapshot['installation'] ?? '') !== $installation || array_diff(array_keys($rows), $tables) || ! hash_equals($snapshot['sha256'] ?? '', hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('The recovery copy is invalid or belongs to a different installation.');
        }
        foreach ($rows as $table => $records) {
            if (DB::table($table)->whereIn('id', array_column($records, 'id'))->exists()) {
                throw new RuntimeException('Restore would overwrite an existing record; nothing was changed.');
            }
        }
        if ($options['apply'] ?? false) {
            $before = protectedBrowserRows($tables, []);
            foreach (array_reverse($tables) as $table) {
                foreach ($rows[$table] ?? [] as $row) {
                    DB::table($table)->insert($row);
                }
            }
            $restoredIds = array_map(fn ($records) => array_column($records, 'id'), $rows);
            if ($before !== protectedBrowserRows($tables, $restoredIds)) {
                throw new RuntimeException('Restore changed a retained record; rolled back.');
            }
        }

        return ['installation' => $installation, 'mode' => 'restore', 'applied' => $options['apply'] ?? false, 'rows' => array_map('count', $rows)];
    }
    if (($project === null) === ! $legacy) {
        throw new RuntimeException('Choose exactly one of --project=desktop/mobile or --legacy.');
    }
    $plan = BrowserFixtures::plan($installation, $project, $legacy);
    if (! ($options['apply'] ?? false)) {
        return ['installation' => $installation, 'applied' => false, 'rows' => array_map('count', $plan)];
    }
    foreach (['crm_leads', 'crm_tasks', 'crm_contacts', 'crm_companies'] as $table) {
        DB::table($table)->whereIn('id', $plan[$table] ?? [])->orderBy('id')->lockForUpdate()->get();
    }
    $locked = $plan;
    $plan = BrowserFixtures::plan($installation, $project, $legacy);
    foreach (['crm_leads', 'crm_tasks', 'crm_contacts', 'crm_companies'] as $table) {
        if (($locked[$table] ?? []) !== ($plan[$table] ?? [])) {
            throw new RuntimeException('The selected test records changed; rerun cleanup.');
        }
    }
    $rows = [];
    foreach ($tables as $table) {
        $rows[$table] = DB::table($table)->whereIn('id', $plan[$table] ?? [])->orderBy('id')->lockForUpdate()->get()->map(fn ($row) => (array) $row)->all();
    }
    $counts = array_map('count', $rows);
    if (array_sum($counts) === 0) {
        return ['installation' => $installation, 'applied' => true, 'rows' => $counts, 'retained_records_unchanged' => true];
    }
    $before = protectedBrowserRows($tables, $plan);
    $recovery = saveBrowserRecovery($directory, $installation, $rows);
    foreach ($tables as $table) {
        $deleted = DB::table($table)->whereIn('id', $plan[$table] ?? [])->delete();
        if ($deleted !== $counts[$table]) {
            throw new RuntimeException('Unexpected cleanup count; rolled back.');
        }
    }
    if ($before !== protectedBrowserRows($tables, [])) {
        throw new RuntimeException('A retained record changed; cleanup rolled back.');
    }

    return ['installation' => $installation, 'applied' => true, 'rows' => $counts, 'recovery' => $recovery, 'retained_records_unchanged' => true];
});
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
