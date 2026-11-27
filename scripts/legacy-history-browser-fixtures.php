<?php

use App\Models\User;
use Awove\Core\Imports\Ledger;
use Awove\Core\Imports\LegacyHistory;
use Awove\Core\Imports\Mapping;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/browser-fixtures.php';
$app = require __DIR__.'/../bootstrap/app.php';
if (($argv[1] ?? '') !== 'alpha' || count($argv) !== 4
    || ! preg_match('/^--run=(desktop|mobile)-[0-9]{13}$/D', $argv[2], $run)
    || ! preg_match('/^--lead=([1-9][0-9]*)$/D', $argv[3], $leadId)) {
    throw new RuntimeException('Choose only an exact disposable Alpha legacy-history browser fixture.');
}
$app->loadEnvironmentFrom('.env.alpha');
$app->make(Kernel::class)->bootstrap();
$project = $run[1];
$runId = substr($argv[2], 6);
$email = 'contact-'.$runId.'@example.test';
$ids = DB::transaction(function () use ($project, $runId, $email, $leadId): array {
    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-browser-fixture-cleanup']);
    $plan = BrowserFixtures::plan('alpha', $project);
    $id = (int) $leadId[1];
    if (! in_array($id, $plan['crm_leads'], true)) {
        throw new RuntimeException('The lead is outside the exact guarded browser fixture plan.');
    }
    $user = User::where('email', 'table-'.$project.'@alpha.example.test')->where('active', true)->whereNotNull('email_verified_at')->firstOrFail();
    if (! $user->can('imports.manage')) {
        throw new RuntimeException('The reserved fixture account lacks its existing import tooling permission.');
    }
    $lead = Lead::whereKey($id)->where('owner_id', $user->id)->where('created_by', $user->id)->whereNull('company_id')->firstOrFail();
    $contact = Contact::whereKey($lead->contact_id)->where('created_by', $user->id)->where('name', 'Shira Levi')->where('email', $email)->firstOrFail();
    if (! BrowserFixtures::identityMatches('contacts', $contact->name, $contact->email, $project)) {
        throw new RuntimeException('The contact is outside the exact fictional run identity.');
    }
    $ledger = app(Ledger::class);
    $history = app(LegacyHistory::class);
    $boundary = $ledger->boundary($user);
    $batch = $ledger->start($user, $boundary, ['batch_key' => (string) Str::uuid(), 'provider' => 'browser-fixture', 'source_account' => 'legacy-history-'.$runId,
        'mapping_revision' => str_repeat('a', 64), 'code_revision' => str_repeat('b', 40), 'plan_revision' => str_repeat('c', 64)]);
    $source = ['board_id' => 'board-'.$runId, 'entity_type' => 'item', 'entity_id' => 'item-'.$lead->id, 'target_role' => 'primary-lead', 'relation_key' => 'primary'];
    $mapping = Mapping::where(['boundary' => $boundary, 'provider' => 'browser-fixture', 'source_account' => $batch['source_account'], ...$source])->first();
    $target = hash_hmac('sha256', json_encode($lead->getAttributes(), JSON_THROW_ON_ERROR), app('encrypter')->getKey());
    $request = (string) Str::uuid();
    $parent = $ledger->claim($user, $boundary, $batch['id'], $source, ['request_key' => $request, 'revision' => 1,
        'source_fingerprint' => hash_hmac('sha256', 'fictional-source-parent:'.$runId, app('encrypter')->getKey()),
        'target_fingerprint' => $mapping ? $target : null, 'previous_receipt_id' => null]);
    if ($parent['state'] === 'claimed') {
        $parent = $ledger->finish($user, $boundary, $parent['id'], $request,
            ['state' => 'verified', 'target_type' => 'core.lead', 'target_id' => (string) $lead->id, 'result_fingerprint' => $target]);
    }
    if ($parent['state'] !== 'verified') {
        throw new RuntimeException('The fictional source parent could not be retained.');
    }
    $input = ['kind' => 'update', 'source_id' => 'update-1', 'source_parent_id' => $source['entity_id'], 'revision' => 1,
        'request_key' => (string) Str::uuid(), 'previous_receipt_id' => null, 'reply_to_mapping_id' => null];
    $payload = ['body_html' => '<p>Reviewed the fictional customer file.</p><p>A second line remains separate.</p><script>window.__legacyExecuted=true</script><img src=x onerror="window.__legacyExecuted=true">',
        'author' => ['source_id' => 'legacy-77', 'name' => 'Yael Cohen'], 'source_created_at' => '2020-02-29T09:30:52.123456+02:00', 'source_updated_at' => '2020-03-01T09:00:00.987654321Z'];
    $write = function (array $data, array $content) use ($history, $user, $boundary, $batch, $parent): array {
        $result = $history->append($user, $boundary, $batch['id'], $parent['mapping_id'], $data, $content);
        if ($result['receipt']['state'] !== 'verified' || $result['activity_id'] === null) {
            throw new RuntimeException('The fictional history fixture was not verified.');
        }

        return $result;
    };
    $update = $write($input, $payload);
    $reply = $write([...$input, 'kind' => 'reply', 'source_id' => 'reply-1', 'request_key' => (string) Str::uuid(), 'reply_to_mapping_id' => $update['receipt']['mapping_id']],
        ['body_html' => '<p>A fictional reply with unavailable source attribution.</p>', 'author' => ['source_id' => null, 'name' => null], 'source_created_at' => null, 'source_updated_at' => null]);
    $created = $write([...$input, 'kind' => 'source-created', 'source_id' => $source['entity_id'], 'request_key' => (string) Str::uuid()], [...$payload, 'body_html' => null]);
    $long = str_repeat("Fictional source history retained.\n", 400).'Final fictional history line.';
    $revision = $write([...$input, 'revision' => 2, 'request_key' => (string) Str::uuid(), 'previous_receipt_id' => $update['receipt']['id']], [...$payload, 'body_html' => '<p>'.$long.'</p>']);

    return ['update_id' => $update['activity_id'], 'reply_id' => $reply['activity_id'], 'source_created_id' => $created['activity_id'], 'revision_id' => $revision['activity_id']];
});
echo json_encode($ids, JSON_THROW_ON_ERROR).PHP_EOL;
