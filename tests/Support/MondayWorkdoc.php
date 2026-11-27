<?php

require_once __DIR__.'/MondayVault.php';

function mvwMainPlanData(): array
{
    $plan = mviPlanData();
    $plan['boards'][0]['columns'][] = ['id' => 'docs', 'type' => 'file'];
    $plan['nodes'][0]['workdocs'] = [['object_id' => '9007199254740993', 'doc_id' => '41300001', 'file_id' => 'fictional-file-id', 'column_id' => 'docs']];

    return $plan;
}

function mvwPlanData(array $main, string $mainHash): array
{
    $node = $main['nodes'][0];

    return ['version' => 1, 'main_plan_hash' => $mainHash, 'run_id' => 'b023df7e-75f1-4bf5-8c55-01e7a9b4fe85',
        'code_revision' => str_repeat('f', 40), 'mapping_revision' => str_repeat('e', 64),
        'documents' => [['source' => ['item_id' => $node['id'], 'board_id' => $node['board_id'], ...$node['workdocs'][0]],
            'blocks' => [['id' => '9200', 'doc_id' => '41300001', 'parent_block_id' => null, 'type' => 'normal text'],
                ['id' => '9201', 'doc_id' => '41300001', 'parent_block_id' => '9200', 'type' => 'bulleted list']]]]];
}

function mvwColumn(): array
{
    return ['id' => 'docs', 'type' => 'file', 'value' => '{"files":[{"fileType":"MONDAY_DOC","objectId":9007199254740993,"fileId":"fictional-file-id","name":"Fictional protected workdoc"}]}',
        'text' => 'Fictional protected workdoc', 'column' => ['title' => 'Protected workdocs']];
}

function mvwMainSource(): array
{
    $data = mviSourceData();
    $data['items'][0]['board']['columns'][] = ['id' => 'docs', 'type' => 'file', 'title' => 'Protected workdocs', 'settings_str' => '{}'];
    $data['items'][0]['column_values'][] = mvwColumn();

    return $data;
}

function mvwPage(int $page): array
{
    $blocks = [['id' => '9200', 'doc_id' => '41300001', 'parent_block_id' => null, 'type' => 'normal text', 'position' => 1.125,
        'content' => '{ "delta" : [{"insert":"  FICTIONAL-WORKDOC-ONLY\\nשלום\\t "}], "large":9007199254740993123456789 }',
        'created_at' => '2020-02-29', 'updated_at' => null, 'created_by' => ['id' => '771', 'name' => 'Yael Cohen']],
        ['id' => '9201', 'doc_id' => '41300001', 'parent_block_id' => '9200', 'type' => 'bulleted list', 'position' => 2.125,
            'content' => '{"delta":[{"insert":"Fictional child"}]}', 'created_at' => '2020-02-29', 'updated_at' => '2020-03-01', 'created_by' => null]];
    $file = ['__typename' => 'FileDocValue', 'object_id' => '9007199254740993', 'file_id' => 'fictional-file-id', 'created_at' => '2020-02-29',
        'creator_id' => '771', 'creator' => ['id' => '771', 'name' => 'Yael Cohen'], 'doc' => ['id' => '41300001', 'object_id' => '9007199254740993']];
    $column = mvwColumn();
    unset($column['column']);
    $column['files'] = [$file];

    return ['me' => ['account' => ['id' => 'fictional-account']], 'version' => ['value' => '2026-10'],
        'items' => [['id' => 'fictional-node', 'board' => ['id' => 'fictional-board'], 'column_values' => [$column]]],
        'docs' => [['id' => '41300001', 'object_id' => '9007199254740993', 'name' => 'Fictional protected workdoc', 'settings' => '{ "rtl" : true, "number":9007199254740993123456789 }',
            'created_at' => '2020-02-29', 'updated_at' => '2020-03-01', 'created_by' => ['id' => '771', 'name' => 'Yael Cohen'], 'doc_kind' => 'private',
            'doc_folder_id' => null, 'workspace_id' => '88', 'blocks' => $page === 1 ? $blocks : []]]];
}
