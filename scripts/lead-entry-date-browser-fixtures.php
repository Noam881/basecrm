<?php

use App\Models\User;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/browser-fixtures.php';
$app = require __DIR__.'/../bootstrap/app.php';
if (($argv[1] ?? '') !== 'alpha' || count($argv) !== 5
    || ! preg_match('/^--run=(desktop|mobile)-[0-9]{13}$/D', $argv[2], $run)
    || ! preg_match('/^--lead=([1-9][0-9]*)$/D', $argv[3], $leadId)
    || ! in_array($argv[4], ['--mode=dated', '--mode=fallback'], true)) {
    throw new RuntimeException('Choose only an exact disposable Alpha lead-entry browser fixture.');
}
$app->loadEnvironmentFrom('.env.alpha');
$app->make(Kernel::class)->bootstrap();
$project = $run[1];
$email = 'contact-'.substr($argv[2], 6).'@example.test';

DB::transaction(function () use ($project, $email, $leadId, $argv) {
    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-browser-fixture-cleanup']);
    // Verifies exact loopback database, non-superuser role, installation and disposable ownership.
    $plan = BrowserFixtures::plan('alpha', $project);
    $id = (int) $leadId[1];
    if (! in_array($id, $plan['crm_leads'], true)) {
        throw new RuntimeException('The lead is outside the guarded browser fixture plan.');
    }
    $user = User::where('email', 'table-'.$project.'@alpha.example.test')->where('active', true)->whereNotNull('email_verified_at')->lockForUpdate()->firstOrFail();
    $lead = Lead::whereKey($id)->where('owner_id', $user->id)->where('created_by', $user->id)->whereNull('company_id')->lockForUpdate()->firstOrFail();
    $contact = Contact::whereKey($lead->contact_id)->where('created_by', $user->id)->where('name', 'Shira Levi')->where('email', $email)->firstOrFail();
    if (! BrowserFixtures::identityMatches('contacts', $contact->name, $contact->email, $project)) {
        throw new RuntimeException('The contact is outside the exact run identity.');
    }
    DB::table('crm_leads')->where('id', $id)->update([
        'entered_on' => $argv[4] === '--mode=dated' ? '2011-12-30' : null,
        'created_at' => '2020-02-29T09:30:52+00:00',
    ]);
});
echo json_encode(['prepared' => true], JSON_THROW_ON_ERROR).PHP_EOL;
