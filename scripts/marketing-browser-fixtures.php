<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\Contact;
use Awove\Core\Services\Leads;
use Awove\Marketing\MarketingModule;
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
    throw new RuntimeException('Only the isolated Alpha PostgreSQL example is permitted.');
}
$mode = $argv[1] ?? '';
$project = $argv[2] ?? '';
if (! in_array($mode, ['setup', 'cleanup'], true) || ! in_array($project, ['desktop', 'mobile'], true)) {
    throw new RuntimeException('Choose setup/cleanup and desktop/mobile.');
}
$email = 'marketing-'.$project.'@alpha.example.test';
$contactEmail = 'marketing-contact-'.$project.'@alpha.example.test';
$u = User::where('email', $email)->first();
if ($u && $u->name !== 'Tamar Ben Ami') {
    throw new RuntimeException('A protected account uses the reserved fixture email.');
}
$result = DB::transaction(function () use ($mode, $project, $email, $contactEmail, $u) {
    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-marketing-fixtures']);
    $contact = Contact::where('email', $contactEmail)->first();
    if ($contact && (! $u || $contact->created_by !== $u->id || $contact->name !== 'Yael Shalev')) {
        throw new RuntimeException('Protected identity uses fixture email.');
    }
    if ($mode === 'setup') {
        Artisan::call('crm:install');
        RateLimiter::clear(md5('magic-login-request'.'magic-request:email:alpha:'.hash('sha256', $email)));
        $fixture = $u ?? User::factory()->create(['name' => 'Tamar Ben Ami', 'email' => $email, 'email_verified_at' => now()]);
        $fixture->forceFill(['active' => true])->save();
        $fixture->assignRole('Employee');
        $fixture->syncPermissions((new MarketingModule)->permissions());
        $manifest = Installation::manifest();
        $manifest['modules'] = ['marketing'];
        $manifest['capabilities'] = ['sales' => true];
        $path = storage_path('app/private/marketing-browser-manifest.json');
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($path, 0600);
        config(['core.manifest' => $path]);
        $contact ??= Contact::create(['name' => 'Yael Shalev', 'email' => $contactEmail, 'phone' => '+1 202-555-0101', 'created_by' => $fixture->id, 'search_key' => 'yaelshalev']);
        $lead = app(Leads::class)->create($fixture, ['contact_id' => $contact->id, 'owner_id' => $fixture->id, 'status_id' => 'new', 'source' => 'Website']);
        $today = now(Installation::settings()['timezone']);

        return ['email' => $email, 'lead_id' => $lead->id, 'contact_id' => $contact->id, 'today' => $today->toDateString(), 'next' => $today->copy()->addDays(7)->toDateString(), 'after' => $today->copy()->addDays(14)->toDateString(), 'currency' => Installation::settings()['currency'], 'manifest' => $path];
    }
    if (! $u) {
        return ['cleaned' => 0];
    }
    $campaigns = DB::table('marketing_campaigns')->where(fn ($q) => $q->where('owner_id', $u->id)->orWhere('created_by', $u->id))->get();
    $content = DB::table('marketing_content')->where(fn ($q) => $q->where('owner_id', $u->id)->orWhere('created_by', $u->id))->get();
    foreach ($campaigns as $c) {
        if ($c->owner_id !== $u->id || $c->created_by !== $u->id || $c->name !== 'Carmel community campaign') {
            throw new RuntimeException('Protected campaign references fixture; cleanup refused.');
        }
    }foreach ($content as $c) {
        if ($c->owner_id !== $u->id || $c->created_by !== $u->id || $c->title !== 'Yael planning guide') {
            throw new RuntimeException('Protected content references fixture; cleanup refused.');
        }
    }
    $leads = DB::table('crm_leads')->where(fn ($q) => $q->where('owner_id', $u->id)->orWhere('created_by', $u->id)->orWhere('contact_id', $contact?->id ?? 0))->get();
    foreach ($leads as $l) {
        if ($l->owner_id !== $u->id || $l->created_by !== $u->id || $l->contact_id !== $contact?->id || $l->title !== 'Yael Shalev') {
            throw new RuntimeException('Protected Lead references fixture; cleanup refused.');
        }
    }
    $reports = DB::table('marketing_performance_reports')->whereIn('campaign_id', $campaigns->pluck('id'))->get();
    if ($reports->contains(fn ($p) => $p->created_by !== $u->id)) {
        throw new RuntimeException('Protected report author.');
    }
    $attributions = DB::table('marketing_attributions')->whereIn('lead_id', $leads->pluck('id'))->get();
    if ($attributions->contains(fn ($a) => $a->updated_by !== $u->id || ($a->linked_campaign_id !== null && ! $campaigns->pluck('id')->contains($a->linked_campaign_id)))) {
        throw new RuntimeException('Protected attribution reference.');
    }
    $history = DB::table('marketing_history')->where(function ($q) use ($campaigns, $content, $reports, $attributions) {
        foreach (['campaigns' => $campaigns, 'content' => $content, 'reports' => $reports, 'attribution' => $attributions] as $k => $rows) {
            $q->orWhere(fn ($sq) => $sq->where('kind', $k)->whereIn('record_id', $rows->pluck('id')));
        }
    })->get();
    $activity = DB::table('crm_activity')->whereIn('lead_id', $leads->pluck('id'))->get();
    $identityHistory = DB::table('crm_identity_activity')->where('contact_id', $contact?->id ?? 0)->get();
    foreach ([$history, $activity, $identityHistory] as $rows) {
        if ($rows->contains(fn ($h) => $h->actor_id !== $u->id)) {
            throw new RuntimeException('Protected fixture history actor.');
        }
    }
    $notices = DB::table('crm_work_reminders')->where('module', 'marketing')->where('source', 'content')->whereIn('record_id', $content->pluck('id'))->get();
    if ($notices->contains(fn ($n) => $n->user_id !== $u->id)) {
        throw new RuntimeException('Protected reminder recipient.');
    }
    $snapshots = DB::table('marketing_attribution_snapshots')->whereIn('lead_id', $leads->pluck('id'))->get();
    if ($snapshots->isNotEmpty()) {
        throw new RuntimeException('Legacy migration snapshots are protected; fixture cleanup refused.');
    }
    $collections = ['crm_work_reminders' => $notices, 'marketing_history' => $history, 'marketing_attributions' => $attributions, 'marketing_performance_reports' => $reports, 'marketing_content' => $content, 'marketing_campaigns' => $campaigns, 'crm_activity' => $activity, 'crm_leads' => $leads, 'crm_identity_activity' => $identityHistory];
    $snapshot = array_map(fn ($rows) => $rows->all(), $collections);
    $snapshot['crm_contacts'] = $contact ? [$contact->getAttributes()] : [];
    if ($contact || $campaigns->isNotEmpty() || $content->isNotEmpty()) {
        $dir = storage_path('app/private/marketing-fixture-recovery');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }$path = $dir.'/'.$project.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';
        file_put_contents($path, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($path, 0600);
    }
    foreach ($collections as $table => $rows) {
        DB::table($table)->whereIn('id', $rows->pluck('id'))->delete();
    }if ($contact) {
        DB::table('crm_contacts')->where('id', $contact->id)->delete();
    }

    return ['cleaned' => $campaigns->count() + $content->count() + $leads->count()];
});
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
