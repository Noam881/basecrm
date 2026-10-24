<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\Contact;
use Awove\CustomerService\CustomerServiceModule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
$email = 'service-'.$project.'@alpha.example.test';
$contactEmail = 'service-contact-'.$project.'@alpha.example.test';
$user = User::where('email', $email)->first();
if ($user && $user->name !== 'Noam Cohen') {
    throw new RuntimeException('A protected account uses the reserved fixture email.');
}

$result = DB::transaction(function () use ($mode, $project, $email, $contactEmail, $user) {
    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-service-fixtures']);
    $contact = Contact::where('email', $contactEmail)->first();
    if ($contact && (! $user || $contact->created_by !== $user->id || $contact->name !== 'Yael Shalev')) {
        throw new RuntimeException('A protected contact uses the reserved fixture email.');
    }
    if ($mode === 'setup') {
        Artisan::call('crm:install');
        $fixtureUser = $user ?? User::factory()->create(['name' => 'Noam Cohen', 'email' => $email, 'active' => true, 'email_verified_at' => now()]);
        $fixtureUser->assignRole('Employee');
        $fixtureUser->givePermissionTo((new CustomerServiceModule)->permissions());
        $fixtureContact = $contact ?? Contact::create(['name' => 'Yael Shalev', 'email' => $contactEmail, 'phone' => '+972 50 000 0000', 'search_key' => 'yaelshalev', 'created_by' => $fixtureUser->id]);
        $manifest = Installation::manifest();
        $manifest['modules'] = ['customer-service'];
        $path = storage_path('app/private/service-browser-manifest.json');
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($path, 0600);

        return ['email' => $email, 'contact_id' => $fixtureContact->id, 'manifest' => $path];
    }
    if (! $user || ! $contact) {
        return ['cleaned' => 0];
    }
    $requests = DB::table('service_requests')->where('contact_id', $contact->id)->get();
    $feedback = DB::table('service_feedback')->where(function ($query) use ($contact, $requests) {
        $query->where('contact_id', $contact->id)->orWhereIn('request_id', $requests->pluck('id'));
    })->get();
    foreach ([...$requests, ...$feedback] as $record) {
        if ($record->owner_id !== $user->id || $record->created_by !== $user->id || $record->contact_id !== $contact->id || $record->company_id !== null) {
            throw new RuntimeException('A protected service record references the fixture; cleanup refused.');
        }
    }
    $history = DB::table('service_history')->where(fn ($query) => $query->whereIn('request_id', $requests->pluck('id'))->orWhereIn('feedback_id', $feedback->pluck('id')))->get();
    if ($history->contains(fn ($record) => $record->actor_id !== $user->id)) {
        throw new RuntimeException('A protected interaction references the fixture; cleanup refused.');
    }
    $notices = DB::table('crm_work_reminders')->where('module', 'customer-service')->where(function ($query) use ($requests, $feedback) {
        $query->where(fn ($q) => $q->where('source', 'requests')->whereIn('record_id', $requests->pluck('id')))->orWhere(fn ($q) => $q->where('source', 'feedback')->whereIn('record_id', $feedback->pluck('id')));
    })->get();
    if ($notices->contains(fn ($record) => $record->user_id !== $user->id)) {
        throw new RuntimeException('A protected reminder references the fixture; cleanup refused.');
    }
    foreach (['crm_leads' => 'contact_id', 'crm_contact_notes' => 'contact_id'] as $table => $column) {
        if (DB::table($table)->where($column, $contact->id)->exists()) {
            throw new RuntimeException('A protected core record references this fixture; cleanup refused.');
        }
    }
    $identityHistory = Schema::hasTable('crm_identity_activity') ? DB::table('crm_identity_activity')->where('contact_id', $contact->id)->get() : collect();
    if ($identityHistory->contains(fn ($record) => $record->actor_id !== $user->id)) {
        throw new RuntimeException('A protected identity history entry references this fixture; cleanup refused.');
    }
    $snapshot = ['crm_identity_activity' => $identityHistory->all(), 'service_feedback' => $feedback->all(), 'service_history' => $history->all(), 'service_requests' => $requests->all(), 'crm_work_reminders' => $notices->all(), 'crm_contacts' => [$contact->getAttributes()]];
    $directory = storage_path('app/private/service-fixture-recovery');
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $path = $directory.'/'.$project.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';
    file_put_contents($path, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    chmod($path, 0600);
    DB::table('crm_work_reminders')->whereIn('id', $notices->pluck('id'))->delete();
    DB::table('service_history')->whereIn('id', $history->pluck('id'))->delete();
    DB::table('service_feedback')->whereIn('id', $feedback->pluck('id'))->delete();
    DB::table('service_requests')->whereIn('id', $requests->pluck('id'))->delete();
    if ($identityHistory->isNotEmpty()) {
        DB::table('crm_identity_activity')->whereIn('id', $identityHistory->pluck('id'))->delete();
    }
    DB::table('crm_contacts')->where('id', $contact->id)->delete();

    return ['cleaned' => $requests->count() + $feedback->count()];
});
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
