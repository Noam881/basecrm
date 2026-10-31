<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Jobs\DeliverWorkReminder;
use Awove\Core\Models\Contact;
use Awove\Nonprofit\Programs\Capability;
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
if (config('database.connections.pgsql.port') != 55438 || $actual->name !== 'base_alpha' || $actual->role !== 'base_alpha' || config('mail.default') !== 'smtp' || config('mail.mailers.smtp.host') !== '127.0.0.1' || config('mail.mailers.smtp.port') != 1028) {
    throw new RuntimeException('Only isolated Alpha and local Mailpit are permitted.');
}
$mode = $argv[1] ?? '';
$project = $argv[2] ?? '';
if (! in_array($mode, ['setup', 'cleanup', 'remind'], true) || ! in_array($project, ['desktop', 'mobile'], true)) {
    throw new RuntimeException('Choose a known mode and project.');
}if (! Schema::hasTable('nonprofit_participants')) {
    throw new RuntimeException('Apply controlled Programs migrations to Alpha first.');
}
$email = 'nonprofit-programs-'.$project.'@alpha.example.test';
$contactEmail = 'nonprofit-programs-contact-'.$project.'@alpha.example.test';
$user = User::where('email', $email)->first();
if ($user && $user->name !== 'Shira Cohen') {
    throw new RuntimeException('Protected account uses reserved email.');
}
function programsFixtureForeign(string $table, $ids, array $allowed): void
{
    $keys = DB::select("SELECT c.conrelid::regclass::text AS table_name,a.attname AS column_name FROM pg_constraint c JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=c.conkey[1] WHERE c.contype='f' AND c.confrelid=?::regclass", [$table]);
    foreach ($keys as $key) {
        if (! in_array($key->table_name, $allowed, true) && DB::table($key->table_name)->whereIn($key->column_name, $ids)->exists()) {
            throw new RuntimeException('Protected foreign relationship references Programs fixture.');
        }
    }
}
$result = DB::transaction(function () use ($mode, $project, $email, $contactEmail, $user) {
    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-programs-fixtures']);
    if ($mode === 'remind') {
        if (! $user) {
            throw new RuntimeException('Set up the exact reserved account first.');
        }
        $manifest = storage_path('app/private/nonprofit-programs-browser-manifest.json');
        if (! is_file($manifest)) {
            throw new RuntimeException('Missing scoped Programs manifest.');
        }
        config(['core.manifest' => $manifest]);
        foreach (DB::table('nonprofit_program_feedback')->where('created_by', $user->id)->where('follow_up_owner_id', $user->id)->get() as $row) {
            (new DeliverWorkReminder('nonprofit', 'program-follow-ups', $row->id, $row->version, $user->id))->handle();
        }

        return ['reminders' => DB::table('crm_work_reminders')->where('module', 'nonprofit')->where('source', 'program-follow-ups')->where('user_id', $user->id)->count()];
    }
    if ($mode === 'setup') {
        $manifest = Installation::manifest();
        $manifest['modules'] = ['nonprofit'];
        $manifest['capabilities'] = ['sales' => false, 'nonprofit:programs' => true, 'nonprofit:fundraising' => false, 'nonprofit:projects' => false];
        $path = storage_path('app/private/nonprofit-programs-browser-manifest.json');
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        config(['core.manifest' => $path]);
        Artisan::call('crm:install');
        $fixture = $user ?? User::factory()->create(['name' => 'Shira Cohen', 'email' => $email, 'email_verified_at' => now()]);
        $fixture->forceFill(['active' => true, 'display_preferences' => null])->save();
        $fixture->assignRole('Employee');
        $fixture->syncPermissions(array_values(array_filter((new Capability)->permissions(), fn ($p) => ! str_ends_with($p, '.view.all') && ! str_ends_with($p, '.edit.all'))));
        if (Contact::where('email', $contactEmail)->exists()) {
            throw new RuntimeException('Reserved identity exists; strict cleanup required.');
        }$contact = Contact::create(['name' => 'Noam Levi', 'email' => $contactEmail, 'phone' => '+1 202-555-0110', 'search_key' => 'noamlevi', 'created_by' => $fixture->id]);
        RateLimiter::clear(md5('magic-login-request'.'magic-request:email:alpha:'.hash('sha256', $email)));
        $today = now(Installation::settings()['timezone']);

        return ['email' => $email, 'owner_id' => $fixture->id, 'contact_id' => $contact->id, 'today' => $today->toDateString(), 'yesterday' => $today->copy()->subDay()->toDateString(), 'next' => $today->copy()->addDays(7)->toDateString(), 'manifest' => $path];
    }
    if (! $user) {
        return ['cleaned' => 0];
    }$all = ['nonprofit_locations', 'nonprofit_groups', 'nonprofit_programs', 'nonprofit_participants', 'nonprofit_sessions', 'nonprofit_attendance', 'nonprofit_program_feedback', 'nonprofit_program_documents', 'nonprofit_program_history'];
    $rows = [];
    foreach (array_slice($all, 0, 8) as $table) {
        $q = DB::table($table)->where('created_by', $user->id);
        if (in_array($table, ['nonprofit_locations', 'nonprofit_groups', 'nonprofit_programs', 'nonprofit_participants'])) {
            $q->orWhere('owner_id', $user->id);
        }$rows[$table] = $q->get();
        foreach ($rows[$table] as $row) {
            if ($row->created_by !== $user->id || (isset($row->owner_id) && $row->owner_id !== $user->id)) {
                throw new RuntimeException('Protected owner uses fixture.');
            }
        }
    }
    $names = ['nonprofit_locations' => ['Jerusalem center'], 'nonprofit_groups' => ['Learning group'], 'nonprofit_programs' => ['Community workshops', 'Community learning'], 'nonprofit_participants' => ['Yael Shalev'], 'nonprofit_sessions' => ['Creative workshop']];
    foreach ($names as $table => $allowed) {
        foreach ($rows[$table] as $row) {
            if (! in_array($row->name, $allowed, true)) {
                throw new RuntimeException('Unrecognized named Programs fixture.');
            }
        }
    }
    $ids = fn ($table) => $rows[$table]->pluck('id');
    foreach (['nonprofit_groups', 'nonprofit_programs', 'nonprofit_participants', 'nonprofit_sessions'] as $table) {
        foreach ($rows[$table] as $row) {
            foreach (['program_id' => 'nonprofit_programs', 'location_id' => 'nonprofit_locations', 'group_id' => 'nonprofit_groups'] as $field => $parent) {
                if (isset($row->$field) && ! $ids($parent)->contains($row->$field)) {
                    throw new RuntimeException('Protected parent linked to fixture.');
                }
            }
        }
    }
    foreach ($rows['nonprofit_participants'] as $row) {
        if ($row->participant_code !== 'P-101' || $row->email !== 'yael.shalev@example.test' || $row->phone !== '+1 202-555-0111') {
            throw new RuntimeException('Protected private participant details.');
        }
    }
    foreach ($rows['nonprofit_attendance'] as $row) {
        if (! $ids('nonprofit_sessions')->contains($row->session_id) || ! $ids('nonprofit_participants')->contains($row->participant_id) || ! in_array($row->status, ['present', 'absent', 'excused'])) {
            throw new RuntimeException('Protected attendance linked to fixture.');
        }
    }
    foreach (['nonprofit_program_feedback', 'nonprofit_program_documents'] as $table) {
        foreach ($rows[$table] as $row) {
            if (! $ids('nonprofit_programs')->contains($row->program_id)) {
                throw new RuntimeException('Protected program child.');
            }
        }
    }
    foreach ($rows['nonprofit_program_feedback'] as $row) {
        if ($row->comments !== 'The group asked for another workshop.' || $row->follow_up_owner_id !== $user->id || $row->next_action !== 'Call group coordinator') {
            throw new RuntimeException('Unrecognized feedback fixture.');
        }
    }foreach ($rows['nonprofit_program_documents'] as $row) {
        if ($row->name !== 'Workshop resource' || $row->url !== 'https://example.test/program-resource.pdf') {
            throw new RuntimeException('Unrecognized document fixture.');
        }
    }
    $history = DB::table('nonprofit_program_history')->where('actor_id', $user->id)->orWhereIn('program_id', $ids('nonprofit_programs'))->orWhereIn('participant_id', $ids('nonprofit_participants'))->get();
    $map = ['locations' => 'nonprofit_locations', 'groups' => 'nonprofit_groups', 'programs' => 'nonprofit_programs', 'participants' => 'nonprofit_participants', 'sessions' => 'nonprofit_sessions', 'attendance' => 'nonprofit_attendance', 'feedback' => 'nonprofit_program_feedback', 'documents' => 'nonprofit_program_documents'];
    foreach ($history as $row) {
        if ($row->actor_id !== $user->id || ! isset($map[$row->kind]) || ! $ids($map[$row->kind])->contains($row->record_id)) {
            throw new RuntimeException('Protected Programs history.');
        }
    }
    $contacts = DB::table('crm_contacts')->where('email', $contactEmail)->get();
    foreach ($contacts as $row) {
        if ($row->created_by !== $user->id || $row->name !== 'Noam Levi') {
            throw new RuntimeException('Protected shared contact.');
        }
    }foreach ($rows['nonprofit_participants'] as $row) {
        if ($row->contact_id !== null && ! $contacts->pluck('id')->contains($row->contact_id)) {
            throw new RuntimeException('Protected Contact link.');
        }
    }
    $notices = DB::table('crm_work_reminders')->where('module', 'nonprofit')->where('source', 'program-follow-ups')->where(fn ($q) => $q->where('user_id', $user->id)->orWhereIn('record_id', $ids('nonprofit_program_feedback')))->get();
    foreach ($notices as $row) {
        if ($row->user_id !== $user->id || ! $ids('nonprofit_program_feedback')->contains($row->record_id)) {
            throw new RuntimeException('Protected notification.');
        }
    }
    foreach (array_slice($all, 0, 8) as $table) {
        programsFixtureForeign($table, $ids($table), $all);
    }programsFixtureForeign('crm_contacts', $contacts->pluck('id'), ['nonprofit_participants', 'crm_identity_activity']);
    $identityHistory = DB::table('crm_identity_activity')->whereIn('contact_id', $contacts->pluck('id'))->get();
    foreach ($identityHistory as $row) {
        if ($row->actor_id !== $user->id || $row->company_id !== null) {
            throw new RuntimeException('Protected identity history.');
        }
    }
    $groups = ['crm_work_reminders' => $notices, 'nonprofit_program_history' => $history, 'nonprofit_program_documents' => $rows['nonprofit_program_documents'], 'nonprofit_program_feedback' => $rows['nonprofit_program_feedback'], 'nonprofit_attendance' => $rows['nonprofit_attendance'], 'nonprofit_sessions' => $rows['nonprofit_sessions'], 'nonprofit_participants' => $rows['nonprofit_participants'], 'nonprofit_programs' => $rows['nonprofit_programs'], 'nonprofit_groups' => $rows['nonprofit_groups'], 'nonprofit_locations' => $rows['nonprofit_locations'], 'crm_identity_activity' => $identityHistory, 'crm_contacts' => $contacts];
    if (collect($groups)->contains(fn ($r) => $r->isNotEmpty())) {
        $dir = storage_path('app/private/nonprofit-programs-fixture-recovery');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }$file = $dir.'/'.$project.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';
        file_put_contents($file, json_encode(array_map(fn ($r) => $r->all(), $groups), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($file, 0600);
    }foreach ($groups as $table => $records) {
        DB::table($table)->whereIn('id', $records->pluck('id'))->delete();
    }

    return ['cleaned' => array_sum(array_map(fn ($r) => $r->count(), $groups))];
});
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
