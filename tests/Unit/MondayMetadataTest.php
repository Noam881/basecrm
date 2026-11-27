<?php

namespace Tests\Unit;

use App\Integrations\Monday\Metadata;
use PHPUnit\Framework\TestCase;

/** Pure metadata sanitization; no Laravel boot, database, filesystem writes or network. */
final class MondayMetadataTest extends TestCase
{
    public function test_it_keeps_only_definitions_and_explicit_coverage_not_private_settings_or_rows(): void
    {
        $source = [...$this->board(), 'description' => 'private-default-value', 'items_page' => ['items' => [['name' => 'private-row-value']]],
            'owners' => [['id' => '12345678901234567890', 'name' => 'private-owner-name', 'email' => 'private-owner-value']],
            'groups' => [['id' => 'group_a', 'title' => 'לקוחות פעילים', 'settings' => 'private-group-value']],
            'columns' => [['id' => 'text', 'title' => 'שם לקוח', 'type' => 'text', 'archived' => false,
                'settings' => ['formula' => 'private-formula-value', 'default' => 'private-default-value', 'labels' => ['private-label-value']]]]];
        $result = (new Metadata)->board($source, 'settings');
        $this->assertTrue($result['ok']);
        $board = $result['board'];
        $this->assertSame([['id' => '12345678901234567890']], $board['owners']);
        $this->assertSame([['id' => 'group_a', 'title' => 'לקוחות פעילים']], $board['groups']);
        $this->assertSame('as_returned', $board['columns_coverage']);
        $this->assertSame('unknown', $board['archive_item_coverage']);
        $this->assertSame('not_inspected', $board['columns'][0]['settings_coverage']);
        $this->assertStringNotContainsString('private-', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_missing_null_or_invalid_required_metadata_never_becomes_an_empty_board(): void
    {
        $sanitizer = new Metadata;
        foreach (['id', 'name', 'state', 'items_count', 'columns'] as $field) {
            foreach (['missing', 'null'] as $case) {
                $board = $this->board();
                if ($case === 'missing') {
                    unset($board[$field]);
                } else {
                    $board[$field] = null;
                }
                $result = $sanitizer->board($board, 'settings');
                $this->assertFalse($result['ok'], $field.': '.$case);
                $this->assertSame('invalid_metadata', $result['error']);
                $this->assertArrayNotHasKey('board', $result);
            }
        }
        foreach ([-1, '0', 0.0, false] as $count) {
            $this->assertFalse($sanitizer->board([...$this->board(), 'items_count' => $count], 'settings')['ok']);
        }
        $this->assertTrue($sanitizer->board([...$this->board(), 'items_count' => 0, 'columns' => []], 'settings')['ok']);
    }

    public function test_decimal_identifiers_remain_exact_and_floating_point_identifiers_fail(): void
    {
        $source = [...$this->board(), 'id' => '00012345678901234567890', 'workspace_id' => '12345678901234567890',
            'columns' => [$this->column('relation', 'board_relation', ['boardIds' => ['12345678901234567890', '000123', '12345678901234567890', 19], 'allowMultipleItems' => true]),
                $this->column('subitems', 'subtasks', ['boardIds' => ['000123', '98765432109876543210']])]];
        $result = (new Metadata)->board($source, 'settings');
        $this->assertTrue($result['ok']);
        $this->assertSame('00012345678901234567890', $result['board']['id']);
        $this->assertSame('12345678901234567890', $result['board']['workspace_id']);
        $this->assertSame(['12345678901234567890', '000123', '19', '98765432109876543210'], $result['board']['related_board_ids']);
        $this->assertTrue($result['board']['columns'][0]['allow_multiple_items']);
        $this->assertNull($result['board']['columns'][1]['allow_multiple_items']);
        $this->assertFalse((new Metadata)->board([...$this->board(), 'id' => 1.2e19], 'settings')['ok']);
    }

    public function test_legacy_json_settings_use_big_integer_strings_without_rounding(): void
    {
        $column = $this->column('relation', 'board_relation', []);
        unset($column['settings']);
        $column['settings_str'] = '{"boardIds":[123456789012345678901234567890],"allowMultipleItems":false,"private_default":"private-setting-value"}';
        $result = (new Metadata)->board([...$this->board(), 'columns' => [$column]], 'settings_str');
        $this->assertTrue($result['ok']);
        $this->assertSame(['123456789012345678901234567890'], $result['board']['related_board_ids']);
        $this->assertSame('parsed', $result['board']['columns'][0]['settings_coverage']);
        $this->assertFalse($result['board']['columns'][0]['allow_multiple_items']);
        $this->assertStringNotContainsString('private-setting-value', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_supported_status_and_dropdown_labels_preserve_ids_duplicate_text_and_hebrew(): void
    {
        $columns = [$this->column('status', 'status', ['labels' => ['0' => 'חדש', '7' => 'בשיחה', '19' => 'בשיחה'], 'formula' => 'private-formula-value']),
            $this->column('dropdown', 'dropdown', ['labels' => [['id' => '001', 'name' => 'מקור א'], ['id' => 2, 'name' => 'מקור ב']]])];
        $result = (new Metadata)->board([...$this->board(), 'columns' => $columns], 'settings');
        $this->assertSame([['id' => '0', 'label' => 'חדש'], ['id' => '7', 'label' => 'בשיחה'], ['id' => '19', 'label' => 'בשיחה']], $result['board']['columns'][0]['label_options']);
        $this->assertSame([['id' => '001', 'label' => 'מקור א'], ['id' => '2', 'label' => 'מקור ב']], $result['board']['columns'][1]['label_options']);
        $this->assertSame('parsed', $result['board']['columns'][0]['settings_coverage']);
        $this->assertStringNotContainsString('private-formula-value', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_unknown_malformed_and_partially_invalid_settings_remain_unresolved_without_values(): void
    {
        $cases = [
            $this->column('a', 'board_relation', null),
            $this->column('b', 'board_relation', []),
            $this->column('c', 'board_relation', ['boardIds' => ['12', 13.0]]),
            $this->column('d', 'board_relation', ['boardIds' => ['12'], 'allowMultipleItems' => 'yes']),
            $this->column('e', 'status', ['labels' => [['id' => 1, 'name' => 'Valid'], ['id' => 1, 'name' => 'private-label-value']]]),
            $this->column('f', 'dropdown', ['labels' => [['id' => 1, 'unknown' => 'private-label-value']]]),
            $this->column('g', 'status', '{broken-private-settings'),
            $this->column('h', 'status', str_repeat('private-setting-value', 7000)),
            $this->column('i', 'status', ['labels' => ['not-an-id' => 'private-label-value']]),
        ];
        $result = (new Metadata)->board([...$this->board(), 'columns' => $cases], 'settings');
        $this->assertTrue($result['ok']);
        foreach ($result['board']['columns'] as $column) {
            $this->assertSame('unresolved', $column['settings_coverage']);
            $this->assertSame([], $column['label_options']);
            $this->assertSame([], $column['related_board_ids']);
        }
        $this->assertStringNotContainsString('private-', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_blank_labels_keep_exact_bytes_and_distinct_ids_without_loosening_title_validation(): void
    {
        $source = [...$this->board(), 'columns' => [
            $this->column('status', 'status', ['labels' => ['0' => '', '4' => '', '9' => '   ']]),
            $this->column('dropdown', 'dropdown', ['labels' => [['id' => 1, 'name' => ''], ['id' => '002', 'name' => '  ']]]),
            $this->column('unknown', 'status', ['private_default' => 'private-setting-value']),
        ]];
        $result = (new Metadata)->board($source, 'settings');
        $this->assertTrue($result['ok']);
        $this->assertSame([['id' => '0', 'label' => ''], ['id' => '4', 'label' => ''], ['id' => '9', 'label' => '   ']], $result['board']['columns'][0]['label_options']);
        $this->assertSame([['id' => '1', 'label' => ''], ['id' => '002', 'label' => '  ']], $result['board']['columns'][1]['label_options']);
        $this->assertSame('parsed', $result['board']['columns'][0]['settings_coverage']);
        $this->assertSame('parsed', $result['board']['columns'][1]['settings_coverage']);
        $this->assertSame('unresolved', $result['board']['columns'][2]['settings_coverage']);
        $this->assertSame([], $result['board']['columns'][2]['label_options']);
        $this->assertStringNotContainsString('private-setting-value', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertFalse((new Metadata)->board([...$this->board(), 'name' => '  '], 'settings')['ok']);
        $blankTitle = [...$this->column('text', 'text', []), 'title' => '  '];
        $this->assertFalse((new Metadata)->board([...$this->board(), 'columns' => [$blankTitle]], 'settings')['ok']);
        foreach (["\x1B", "\xC3\x28", str_repeat('א', 513)] as $invalid) {
            $column = $this->column('status', 'status', ['labels' => ['0' => $invalid]]);
            $checked = (new Metadata)->board([...$this->board(), 'columns' => [$column]], 'settings');
            $this->assertTrue($checked['ok']);
            $this->assertSame('unresolved', $checked['board']['columns'][0]['settings_coverage']);
            $this->assertSame([], $checked['board']['columns'][0]['label_options']);
        }
    }

    public function test_archival_and_missing_optional_visibility_evidence_are_preserved(): void
    {
        $column = $this->column('status', 'status', ['labels' => []]);
        unset($column['archived']);
        $result = (new Metadata)->board([...$this->board(), 'state' => 'archived', 'hierarchy_type' => null, 'workspace_id' => null,
            'columns' => [$column]], 'settings');
        $this->assertTrue($result['ok']);
        $this->assertSame('archived', $result['board']['state']);
        $this->assertNull($result['board']['workspace_id']);
        $this->assertNull($result['board']['hierarchy_type']);
        $this->assertNull($result['board']['columns'][0]['archived']);
        $this->assertSame('unknown', $result['board']['archive_item_coverage']);
        $this->assertSame('parsed', $result['board']['columns'][0]['settings_coverage']);
    }

    public function test_oversized_control_character_or_duplicate_definitions_fail_without_truncation(): void
    {
        $cases = [
            [...$this->board(), 'name' => str_repeat('א', 513)],
            [...$this->board(), 'name' => "secret\x1B[31m"],
            [...$this->board(), 'columns' => [null]],
            [...$this->board(), 'columns' => [$this->column('same', 'text', []), $this->column('same', 'text', [])]],
            [...$this->board(), 'owners' => [null]],
            [...$this->board(), 'columns' => [$this->column('text', 'text', []) + ['unknown' => true]], 'groups' => [['id' => 'a', 'title' => null]]],
        ];
        foreach ($cases as $source) {
            $this->assertSame(['ok' => false, 'error' => 'invalid_metadata', 'board_id' => '18392036417'], (new Metadata)->board($source, 'settings'));
        }
        $this->assertFalse((new Metadata)->board($this->board(), 'column_values')['ok']);
    }

    public function test_source_text_stays_literal_and_settings_are_never_executed(): void
    {
        $literal = '<img src=x onerror="throw new Error()">';
        $source = [...$this->board(), 'name' => $literal, 'columns' => [$this->column('formula', 'formula',
            ['formula' => 'throw new RuntimeException("private-executable-value")', 'default' => 'private-default-value'])]];
        $result = (new Metadata)->board($source, 'settings');
        $this->assertTrue($result['ok']);
        $this->assertSame($literal, $result['board']['name']);
        $this->assertSame('not_inspected', $result['board']['columns'][0]['settings_coverage']);
        $this->assertStringNotContainsString('private-', json_encode($result, JSON_THROW_ON_ERROR));
    }

    private function board(): array
    {
        return ['id' => '18392036417', 'name' => 'לידים', 'state' => 'active', 'items_count' => 4,
            'columns' => [$this->column('text', 'text', [])]];
    }

    private function column(string $id, string $type, mixed $settings): array
    {
        return ['id' => $id, 'title' => 'עמודה '.$id, 'type' => $type, 'archived' => false, 'settings' => $settings];
    }
}
