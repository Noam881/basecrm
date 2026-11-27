<?php

namespace Tests\Unit;

use Awove\Vault\Imports\MondayAsset;
use Awove\Vault\Imports\MondayPlan;
use Awove\Vault\Imports\MondaySnapshot;
use Awove\Vault\Imports\MondaySource;
use Awove\Vault\Imports\MondayToken;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require_once __DIR__.'/../Support/MondayVault.php';

class VaultMondayImportTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = realpath(sys_get_temp_dir()).'/vault-monday-unit-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory.'/manifest.json', json_encode(['id' => 'fictional-vault-import', 'modules' => ['vault']]));
        config(['core.manifest' => $this->directory.'/manifest.json', 'app.debug' => false, 'vault.key_file' => null,
            'vault.active_key' => 'fictional-key', 'vault.keys' => ['fictional-key' => base64_encode(random_bytes(32))],
            'vault.monday_token_file' => $this->directory.'/token.json', 'vault.monday_plan_file' => $this->directory.'/plan.json']);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_token_is_encrypted_bound_private_and_rejects_tampering_or_unsafe_permissions(): void
    {
        $tokens = app(MondayToken::class);
        $token = 'fictional-token-'.bin2hex(random_bytes(16));
        $tokens->configure('fictional-vault-import', $token, $token);
        $path = $this->directory.'/token.json';
        $this->assertSame($token, $tokens->read());
        $this->assertStringNotContainsString($token, file_get_contents($path));
        $this->assertSame(0, fileperms($path) & 0077);
        chmod($path, 0644);
        $this->assertFalse($tokens->configured());
        chmod($path, 0600);
        $data = json_decode(file_get_contents($path), true);
        $data['ciphertext'][0] = $data['ciphertext'][0] === 'A' ? 'B' : 'A';
        file_put_contents($path, json_encode($data));
        $this->assertFalse($tokens->configured());
    }

    public function test_token_never_normalizes_whitespace_and_refuses_wrong_installation_and_symlink(): void
    {
        $tokens = app(MondayToken::class);
        foreach ([' fictionally-long-token ', "fictional-token\nheader"] as $token) {
            try {
                $tokens->configure('fictional-vault-import', $token, $token);
                $this->fail('Whitespace must be rejected.');
            } catch (HttpException) {
                $this->assertFileDoesNotExist($this->directory.'/token.json');
            }
        }
        symlink($this->directory.'/manifest.json', $this->directory.'/token.json');
        $this->expectException(HttpException::class);
        $tokens->configure('fictional-vault-import', 'fictional-long-token', 'fictional-long-token');
    }

    public function test_plan_rejects_hash_changes_scope_aliases_unknown_keys_and_broken_lineage(): void
    {
        $plan = \mviPlanData();
        file_put_contents($this->directory.'/plan.json', json_encode($plan));
        chmod($this->directory.'/plan.json', 0600);
        config(['vault.monday_plan_sha256' => hash_file('sha256', $this->directory.'/plan.json')]);
        $this->assertSame($plan, MondayPlan::load()->data);
        foreach (['duplicate', 'lineage', 'unknown', 'account'] as $change) {
            $changed = $plan;
            match ($change) {
                'duplicate' => $changed['nodes'][] = $changed['nodes'][0],
                'lineage' => $changed['nodes'][0]['parent_id'] = 'unknown-parent',
                'unknown' => $changed['credential'] = 'forbidden-extra-key',
                'account' => $changed['account_id'] = 'not an opaque ID',
            };
            try {
                MondayPlan::validate($changed, str_repeat('a', 64));
                $this->fail('Invalid scope must be refused.');
            } catch (\Throwable $error) {
                $this->assertNotInstanceOf(AssertionFailedError::class, $error);
            }
        }
        file_put_contents($this->directory.'/plan.json', json_encode([...$plan, 'api_version' => 'changed']));
        $this->expectException(HttpException::class);
        MondayPlan::load();
    }

    public function test_source_preserves_exact_raw_json_whitespace_history_and_encrypted_export_chunks(): void
    {
        $plan = MondayPlan::validate(\mviPlanData(), str_repeat('a', 64));
        $source = app(MondaySource::class)->validate($plan, $plan->data['nodes'][0], \mviSourceData());
        $source['column_values'][0]['text'] = str_repeat('ש', 50000)."\n ";
        $payload = MondaySnapshot::payload($plan, $plan->data['nodes'][0], $source);
        $export = json_decode(MondaySnapshot::export($payload), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame($source, $export['original']);
        $this->assertSame('{"identifier":"9007199254740993123456789","empty":"","null":null}', $export['original']['column_values'][0]['value']);
        $this->assertSame('Yael Cohen', $export['original']['updates'][0]['creator']['name']);
        $this->assertGreaterThan(2, count($payload['fields']));
    }

    public function test_source_refuses_partial_or_wrong_parent_column_type_history_or_prohibited_data(): void
    {
        $plan = MondayPlan::validate(\mviPlanData(), str_repeat('a', 64));
        foreach (['account', 'version', 'parent', 'column', 'type', 'history', 'reply', 'prohibited'] as $change) {
            $data = \mviSourceData();
            match ($change) {
                'account' => $data['me']['account']['id'] = 'another-account',
                'version' => $data['version']['value'] = 'different',
                'parent' => $data['items'][0]['parent_item'] = ['id' => 'wrong-parent'],
                'column' => $data['items'][0]['column_values'] = [],
                'type' => $data['items'][0]['column_values'][0]['type'] = 'email',
                'history' => $data['items'][0]['updates'][0]['item_id'] = 'wrong-item',
                'reply' => $data['items'][0]['updates'][0]['replies'] = [['id' => 'unexpected-reply']],
                'prohibited' => $data['items'][0]['board']['columns'][1]['title'] = 'Card CVV',
            };
            try {
                app(MondaySource::class)->validate($plan, $plan->data['nodes'][0], $data);
                $this->fail('Source mismatch must be refused.');
            } catch (HttpException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_formula_display_value_is_retained_separately_without_coercing_raw_numeric_json(): void
    {
        $data = \mviSourceData();
        $scope = \mviPlanData();
        $scope['boards'][0]['columns'][1]['type'] = 'formula';
        $data['items'][0]['board']['columns'][1]['type'] = 'formula';
        $data['items'][0]['column_values'][0] = [...$data['items'][0]['column_values'][0], 'type' => 'formula', 'value' => null, 'text' => null, 'display_value' => '9007199254740993.00001'];
        $plan = MondayPlan::validate($scope, str_repeat('a', 64));
        $source = app(MondaySource::class)->validate($plan, $plan->data['nodes'][0], $data);
        $payload = MondaySnapshot::payload($plan, $plan->data['nodes'][0], $source);
        $original = json_decode(MondaySnapshot::export($payload), true);
        $this->assertNull($original['original']['column_values'][0]['value']);
        $this->assertSame('9007199254740993.00001', $original['original']['column_values'][0]['display_value']);
        unset($data['items'][0]['column_values'][0]['display_value']);
        $this->expectException(HttpException::class);
        app(MondaySource::class)->validate($plan, $plan->data['nodes'][0], $data);
    }

    public function test_asset_location_requires_the_exact_source_occurrence_and_keeps_capability_out_of_provenance(): void
    {
        $plan = MondayPlan::validate(\mviFilePlan(\mviPlanData()), str_repeat('a', 64));
        $node = $plan->data['nodes'][0];
        $occurrence = $node['asset_occurrences'][0];
        $column = \mviFileSourceData()['items'][0]['column_values'][1];
        [$location, $source] = app(MondayAsset::class)->location($plan, $node, $occurrence, $column, \mviAssetData());
        $this->assertSame('12345001', $location->id());
        $this->assertSame('Yael Cohen', $source['creator']['name']);
        $this->assertStringNotContainsString('signature', json_encode($source));
        $this->assertStringNotContainsString('public_url', json_encode($source));
        $changed = \mviAssetData();
        $changed['items'][0]['column_values'][0]['files'][0]['asset_id'] = '12345002';
        $this->expectException(HttpException::class);
        app(MondayAsset::class)->location($plan, $node, $occurrence, $column, $changed);
    }

    public function test_real_numeric_update_identifiers_remain_exact_strings_in_scope_and_snapshot(): void
    {
        $scope = \mviPlanData();
        $scope['nodes'][0]['update_ids'] = ['1234567890'];
        $data = \mviSourceData();
        $data['items'][0]['updates'][0]['id'] = '1234567890';
        $plan = MondayPlan::validate($scope, str_repeat('a', 64));
        $source = app(MondaySource::class)->validate($plan, $plan->data['nodes'][0], $data);
        $this->assertSame('1234567890', $source['updates'][0]['id']);
        $source['updates'][0]['id'] = '01234567890';
        $data['items'][0] = $source;
        $this->expectException(HttpException::class);
        app(MondaySource::class)->validate($plan, $plan->data['nodes'][0], $data);
    }
}
