<?php

namespace Tests\Unit;

use Awove\Vault\Imports\MondayPlan;
use Awove\Vault\Imports\MondayWorkdocPlan;
use Awove\Vault\Imports\MondayWorkdocSnapshot;
use Awove\Vault\Imports\MondayWorkdocSource;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require_once __DIR__.'/../Support/MondayWorkdoc.php';

class VaultMondayWorkdocTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = realpath(sys_get_temp_dir()).'/vault-workdoc-unit-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory.'/manifest.json', json_encode(['id' => 'fictional-vault-import', 'modules' => ['vault']]));
        config(['core.manifest' => $this->directory.'/manifest.json']);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function plan(?array $data = null): MondayWorkdocPlan
    {
        $main = MondayPlan::validate(mvwMainPlanData(), str_repeat('a', 64));

        return MondayWorkdocPlan::validate($data ?? mvwPlanData($main->data, $main->hash), str_repeat('b', 64), $main);
    }

    public function test_preserves_exact_json_scalar_strings_date_only_author_hierarchy_and_numeric_ids(): void
    {
        $plan = $this->plan();
        [$doc, $file] = app(MondayWorkdocSource::class)->page($plan, $plan->data['documents'][0], mvwColumn(), mvwPage(1), 0);
        $original = ['document' => $doc, 'attachment' => $file, 'terminal_empty_page' => 2];
        $payload = MondayWorkdocSnapshot::payload($plan, $plan->data['documents'][0], 'parent-entry', 'parent-item', $original);
        $parsed = json_decode(MondayWorkdocSnapshot::export($payload), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        $this->assertSame($original, $parsed['original']);
        $this->assertSame('2020-02-29', $parsed['original']['document']['created_at']);
        $this->assertSame('9200', $parsed['original']['document']['blocks'][1]['parent_block_id']);
        $this->assertSame($doc['settings'], $parsed['original']['document']['settings']);
        $this->assertSame($doc['blocks'][0]['content'], $parsed['original']['document']['blocks'][0]['content']);
        $this->assertTrue($payload['fields'][0]['hidden']);
    }

    public function test_rejects_invented_extra_documents_parent_cycles_and_unsupported_block_types(): void
    {
        $base = mvwPlanData(mvwMainPlanData(), str_repeat('a', 64));
        $cases = [];
        $case = $base;
        $case['main_plan_hash'] = str_repeat('c', 64);
        $cases[] = $case;
        $case = $base;
        $case['documents'][0]['source']['doc_id'] = 'other';
        $cases[] = $case;
        $case = $base;
        $case['documents'][0]['blocks'][0]['type'] = 'image';
        $cases[] = $case;
        $case = $base;
        $case['documents'][0]['blocks'][0]['parent_block_id'] = '9201';
        $cases[] = $case;
        $case = $base;
        $case['documents'][0]['blocks'][] = $case['documents'][0]['blocks'][0];
        $cases[] = $case;
        foreach ($cases as $case) {
            try {
                $this->plan($case);
                $this->fail('Unproved workdoc scope must be refused.');
            } catch (HttpException $error) {
                $this->assertSame(503, $error->getStatusCode());
            }
        }
    }

    public function test_rejects_changed_ownership_json_scalar_shapes_order_partial_and_unknown_blocks(): void
    {
        $plan = $this->plan();
        $base = mvwPage(1);
        $cases = [];
        $case = $base;
        $case['items'][0]['column_values'][0]['files'][0]['doc']['id'] = 'other';
        $cases[] = $case;
        $case = $base;
        $case['docs'][0]['settings'] = ['decoded' => 'lossy'];
        $cases[] = $case;
        $case = $base;
        $case['docs'][0]['blocks'][0]['content'] = ['decoded' => 'lossy'];
        $cases[] = $case;
        $case = $base;
        $case['docs'][0]['blocks'] = array_reverse($case['docs'][0]['blocks']);
        $cases[] = $case;
        $case = $base;
        unset($case['docs'][0]['created_by']);
        $cases[] = $case;
        $case = $base;
        $case['docs'][0]['blocks'][0]['id'] = 'unknown';
        $cases[] = $case;
        foreach ($cases as $case) {
            try {
                app(MondayWorkdocSource::class)->page($plan, $plan->data['documents'][0], mvwColumn(), $case, 0);
                $this->fail('Changed or partial source data must be refused.');
            } catch (HttpException|ValidationException $error) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_oversize_source_is_not_truncated_into_native_fields(): void
    {
        $plan = $this->plan();
        $this->expectException(HttpException::class);
        MondayWorkdocSnapshot::payload($plan, $plan->data['documents'][0], 'parent-entry', 'parent-item', ['bytes' => str_repeat('F', 450001)]);
    }
}
