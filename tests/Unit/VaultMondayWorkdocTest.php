<?php

namespace Tests\Unit;

use Awove\Vault\Imports\MondayPlan;
use Awove\Vault\Imports\MondaySource;
use Awove\Vault\Imports\MondayWorkdocPlan;
use Awove\Vault\Imports\MondayWorkdocSnapshot;
use Awove\Vault\Imports\MondayWorkdocSource;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
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
        $case['me']['account']['id'] = 'other-account';
        $cases[] = $case;
        $case = $base;
        $case['version']['value'] = 'other-version';
        $cases[] = $case;
        $case = $base;
        $case['items'][0]['column_values'][0]['files'][0]['doc']['object_id'] = 'other-object';
        $cases[] = $case;
        foreach (['__typename' => 'FileAssetValue', 'file_id' => 'other-file', 'object_id' => 'other-object'] as $key => $value) {
            $case = $base;
            $case['items'][0]['column_values'][0]['files'][0][$key] = $value;
            $cases[] = $case;
        }
        foreach (['id' => 'other-item', 'board' => ['id' => 'other-board']] as $key => $value) {
            $case = $base;
            $case['items'][0][$key] = $value;
            $cases[] = $case;
        }
        $case = $base;
        $case['items'][0]['column_values'][0]['value'] = '{"files":[]}';
        $cases[] = $case;
        $case = $base;
        $case['items'][0]['column_values'][0]['files'][0]['doc']['id'] = 'other';
        $cases[] = $case;
        $case = $base;
        $case['items'][0]['column_values'][0]['files'][0]['doc']['settings'] = ['decoded' => 'lossy'];
        $cases[] = $case;
        $case = $base;
        $case['items'][0]['column_values'][0]['files'][0]['doc']['blocks'][0]['content'] = ['decoded' => 'lossy'];
        $cases[] = $case;
        $case = $base;
        $case['items'][0]['column_values'][0]['files'][0]['doc']['blocks'] = array_reverse($case['items'][0]['column_values'][0]['files'][0]['doc']['blocks']);
        $cases[] = $case;
        $case = $base;
        unset($case['items'][0]['column_values'][0]['files'][0]['doc']['created_by']);
        $cases[] = $case;
        $case = $base;
        $case['items'][0]['column_values'][0]['files'][0]['doc']['blocks'][0]['id'] = 'unknown';
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

    public function test_fetches_paginated_document_from_its_owning_file_without_root_docs(): void
    {
        $plan = $this->plan();
        $request = Request::create('/vault/api/imports/monday/workdocs/next', 'POST');
        $pages = [];
        $source = $this->mock(MondaySource::class);
        $source->shouldReceive('query')->twice()->withArgs(function ($actualRequest, $query, $variables) use ($request, &$pages): bool {
            $this->assertSame($request, $actualRequest);
            $this->assertStringNotContainsString('docs(', $query);
            $this->assertStringContainsString('FileDocValue', $query);
            $this->assertStringContainsString('doc { id object_id name settings', $query);
            $this->assertSame(['fictional-node'], $variables['items']);
            $this->assertSame(['docs'], $variables['columns']);
            $this->assertArrayNotHasKey('ids', $variables);
            $pages[] = $variables['page'];

            return true;
        })->andReturn(mvwPage(1), mvwPage(2));
        $authorizations = 0;
        $result = app(MondayWorkdocSource::class)->fetch($request, $plan, $plan->data['documents'][0], mvwColumn(), function () use (&$authorizations): void {
            $authorizations++;
        });
        $this->assertSame([1, 2], $pages);
        $this->assertSame(5, $authorizations);
        $this->assertSame(mvwPage(1)['items'][0]['column_values'][0]['files'][0]['doc'], $result['document']);
        $this->assertSame(['id' => '41300001', 'object_id' => '9007199254740993'], $result['attachment']['doc']);
        $this->assertSame(2, $result['terminal_empty_page']);
    }

    public function test_never_adopts_a_root_document_when_the_owning_file_document_is_missing(): void
    {
        $plan = $this->plan();
        $data = mvwPage(1);
        $data['docs'] = [$data['items'][0]['column_values'][0]['files'][0]['doc']];
        $data['items'][0]['column_values'][0]['files'][0]['doc'] = null;
        $this->expectException(HttpException::class);
        app(MondayWorkdocSource::class)->page($plan, $plan->data['documents'][0], mvwColumn(), $data, 0);
    }

    public function test_oversize_source_is_not_truncated_into_native_fields(): void
    {
        $plan = $this->plan();
        $this->expectException(HttpException::class);
        MondayWorkdocSnapshot::payload($plan, $plan->data['documents'][0], 'parent-entry', 'parent-item', ['bytes' => str_repeat('F', 450001)]);
    }
}
