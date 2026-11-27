<?php

function mviPlanData(string $installation = 'fictional-vault-import'): array
{
    return ['version' => 1, 'installation_id' => $installation, 'boundary' => str_repeat('a', 64), 'run_id' => '1526a74b-31b3-45de-8c0a-442615d59704',
        'account_id' => 'fictional-account', 'api_version' => '2026-10', 'code_revision' => str_repeat('b', 40), 'mapping_revision' => str_repeat('c', 64), 'company_mapping_id' => 1,
        'boards' => [['id' => 'fictional-board', 'columns' => [['id' => 'name', 'type' => 'name'], ['id' => 'access', 'type' => 'text']]]],
        'nodes' => [['id' => 'fictional-node', 'board_id' => 'fictional-board', 'parent_id' => null, 'update_ids' => ['fictional-update'], 'assets' => [], 'workdocs' => []]]];
}

function mviSourceData(): array
{
    return ['me' => ['account' => ['id' => 'fictional-account']], 'version' => ['value' => '2026-10'], 'items' => [[
        'id' => 'fictional-node', 'name' => 'Fictional private access', 'created_at' => '2020-02-29T09:30:52.123456789+02:00', 'updated_at' => null,
        'creator' => ['id' => 'fictional-user', 'name' => 'Yael Cohen'], 'board' => ['id' => 'fictional-board', 'name' => 'Fictional secure board',
            'columns' => [['id' => 'name', 'type' => 'name', 'title' => 'Name', 'settings_str' => '{}'], ['id' => 'access', 'type' => 'text', 'title' => 'Access value', 'settings_str' => '{}']]],
        'group' => ['id' => 'fictional-group', 'title' => 'Fictional private group'], 'parent_item' => null,
        'column_values' => [['id' => 'access', 'type' => 'text', 'text' => "  FICTIONAL-VAULT-IMPORT\nשורה נוספת\t ", 'value' => '{"identifier":"9007199254740993123456789","empty":"","null":null}', 'column' => ['title' => 'Access value']]],
        'updates' => [['id' => 'fictional-update', 'item_id' => 'fictional-node', 'body' => '<p>Fictional original <b>conversation</b>.</p>',
            'created_at' => '2020-02-29T09:30:52Z', 'updated_at' => null, 'creator' => ['id' => 'fictional-user', 'name' => 'Yael Cohen'], 'replies' => [], 'assets' => []]],
    ]]];
}

function mviFilePlan(array $plan): array
{
    $plan['boards'][0]['columns'][] = ['id' => 'files', 'type' => 'file'];
    $plan['nodes'][0]['assets'] = ['12345001'];
    $plan['nodes'][0]['asset_occurrences'] = [['id' => '12345001', 'column_id' => 'files', 'index' => 0]];

    return $plan;
}

function mviFileSourceData(): array
{
    $data = mviSourceData();
    $data['items'][0]['board']['columns'][] = ['id' => 'files', 'type' => 'file', 'title' => 'Private original files', 'settings_str' => '{}'];
    $data['items'][0]['column_values'][] = ['id' => 'files', 'type' => 'file', 'text' => 'Yael Cohen original proposal.pdf',
        'value' => '{"files":[{"assetId":12345001,"name":"Yael Cohen original proposal.pdf","fileType":"ASSET"}]}', 'column' => ['title' => 'Private original files']];

    return $data;
}

function mviFileBytes(): string
{
    return "%PDF-1.4\n% FICTIONAL-ENCRYPTED-FILE-ONLY\n%%EOF";
}

function mviAssetData(): array
{
    $column = mviFileSourceData()['items'][0]['column_values'][1];
    unset($column['column']);
    $column['files'] = [['__typename' => 'FileAssetValue', 'asset_id' => '12345001', 'name' => 'Yael Cohen original proposal.pdf',
        'created_at' => '2020-02-29T09:30:52.123456789+02:00', 'creator_id' => 'fictional-user', 'creator' => ['id' => 'fictional-user', 'name' => 'Yael Cohen'],
        'asset' => ['id' => '12345001', 'name' => 'Yael Cohen original proposal.pdf', 'file_extension' => 'pdf', 'file_size' => strlen(mviFileBytes()),
            'created_at' => '2020-02-29T09:30:52Z', 'public_url' => 'https://files.monday.com/fictional-only?signature=fictional-capability']]];

    return ['me' => ['account' => ['id' => 'fictional-account']], 'version' => ['value' => '2026-10'],
        'items' => [['id' => 'fictional-node', 'board' => ['id' => 'fictional-board'], 'column_values' => [$column]]]];
}
