<?php

namespace Awove\Core\Tables;

use Awove\Core\Contracts\LeadTableContributor;
use Awove\Core\Installation;
use Awove\Core\Models\Status;
use Awove\Core\Modules;

class Catalog
{
    /** @return array<string,Column> */
    public function columns(string $table, $user): array
    {
        $c = [];
        $add = function (string $id, string $label, string $sql, string $type = 'text', string $group = 'Record', array $options = [], int $width = 180) use (&$c) {
            $c[$id] = new Column($id, $label, $sql, $type, $group, $options, $width);
        };
        $person = fn (string $fk) => "(SELECT name FROM users WHERE users.id = $fk)";
        $priorities = [['value' => 'low', 'label' => 'Low'], ['value' => 'normal', 'label' => 'Normal'], ['value' => 'high', 'label' => 'High']];
        if ($table === 'leads') {
            $t = 'crm_leads';
            $add('id', 'Lead ID', "$t.id", 'number');
            $add('title', 'Lead title', "$t.title", width: 280);
            foreach (['contact' => 'crm_contacts', 'company' => 'crm_companies'] as $key => $related) {
                foreach (['name', 'email', 'phone'] as $field) {
                    $add($field === 'name' ? $key : $key.'_'.$field, ucfirst($key).' '.ucfirst($field), "(SELECT $field FROM $related WHERE $related.id=$t.{$key}_id)", 'text', 'Customer', width: $field === 'email' ? 240 : 200);
                }
            }
            $add('owner', 'Owner', $person("$t.owner_id"), 'text', 'Ownership');
            $add('owner_id', 'Owner ID', "$t.owner_id", 'number', 'Ownership');
            $add('created_by', 'Created by', $person("$t.created_by"), 'text', 'Ownership');
            $add('status', 'Stage', "(SELECT name FROM crm_statuses WHERE id=$t.status_id)", 'select', 'Pipeline', Status::orderBy('position')->get()->map(fn ($s) => ['value' => $s->name, 'label' => $s->name])->all());
            $add('outcome', 'Stage outcome', "(SELECT kind FROM crm_statuses WHERE id=$t.status_id)", 'select', 'Pipeline', array_map(fn ($s) => ['value' => $s, 'label' => ucfirst($s)], ['active', 'won', 'lost']));
            $add('priority', 'Priority', "$t.priority", 'select', 'Pipeline', $priorities, 140);
            $add('source', 'Source', "$t.source", group: 'Pipeline');
            $add('next_action_at', 'Next follow-up', "(SELECT MIN(due_at) FROM crm_tasks WHERE lead_id=$t.id AND status='open')", 'datetime', 'Follow-ups', width: 220);
            $add('open_tasks', 'Open follow-ups', "(SELECT COUNT(*) FROM crm_tasks WHERE lead_id=$t.id AND status='open')", 'number', 'Follow-ups');
            $add('overdue_tasks', 'Overdue follow-ups', "(SELECT COUNT(*) FROM crm_tasks WHERE lead_id=$t.id AND status='open' AND due_at<CURRENT_TIMESTAMP)", 'number', 'Follow-ups');
            $add('notes_count', 'Notes', "(SELECT COUNT(*) FROM crm_notes WHERE lead_id=$t.id)", 'number', 'Activity');
            $add('last_activity_at', 'Last activity', "(SELECT MAX(created_at) FROM crm_activity WHERE lead_id=$t.id)", 'datetime', 'Activity', width: 220);
            $add('external_reference', 'External reference', "$t.external_reference");
            $add('version', 'Record version', "$t.version", 'number', 'Activity');
            foreach (Installation::manifest()['custom_fields'] as $key => $field) {
                if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $key)) {
                    throw new \RuntimeException('Custom table field identifiers must be alphanumeric.');
                }$sql = "NULLIF($t.custom_fields->>'$key','')";
                if ($field['type'] === 'number') {
                    $sql = "($sql)::numeric";
                }if ($field['type'] === 'date') {
                    $sql = "($sql)::date";
                }$options = array_map(fn ($v) => ['value' => $v, 'label' => $v], $field['options'] ?? []);
                $add('custom:'.$key, $field['label'], $sql, $field['type'] === 'text' ? 'text' : $field['type'], 'Custom fields', $options);
            }
            foreach (app(Modules::class)->installed() as $id => $module) {
                if (app(Modules::class)->enabled($id) && $module instanceof LeadTableContributor) {
                    foreach ($module->leadTableColumns($user) as $column) {
                        if (isset($c[$column->id])) {
                            throw new \RuntimeException('Duplicate table field.');
                        }$c[$column->id] = $column;
                    }
                }
            }
        } elseif (in_array($table, ['contacts', 'companies'])) {
            $t = 'crm_'.$table;
            $add('id', 'Record ID', "$t.id", 'number');
            $add('name', 'Name', "$t.name", width: 240);
            $add('email', 'Email', "$t.email", width: 240);
            $add('phone', 'Phone', "$t.phone");
            $add('created_by', 'Created by', $person("$t.created_by"), 'text', 'Ownership');
            if ($table === 'contacts') {
                $add('company', 'Company', "(SELECT name FROM crm_companies WHERE id=$t.company_id)", 'text', 'Customer', width: 220);
            }
        } elseif ($table === 'tasks') {
            $t = 'crm_tasks';
            $add('id', 'Follow-up ID', "$t.id", 'number');
            $add('title', 'Follow-up', "$t.title", width: 280);
            $add('lead', 'Lead', "(SELECT title FROM crm_leads WHERE id=$t.lead_id)", 'text', 'Customer', width: 240);
            $add('assignee', 'Assignee', $person("$t.assignee_id"), 'text', 'Ownership');
            $add('priority', 'Priority', "$t.priority", 'select', 'Follow-up', $priorities);
            $add('status', 'Status', "$t.status", 'select', 'Follow-up', array_map(fn ($v) => ['value' => $v, 'label' => ucfirst($v)], ['open', 'completed', 'cancelled']));
            $add('due_at', 'Deadline', "$t.due_at", 'datetime', 'Schedule', width: 220);
            $add('remind_at', 'Reminder', "$t.remind_at", 'datetime', 'Schedule', width: 220);
            $add('version', 'Record version', "$t.version", 'number', 'Activity');
        } else {
            abort(404);
        }
        foreach (['created_at' => 'Created', 'updated_at' => 'Last updated'] as $id => $label) {
            $add($id,$label,"$t.$id",'datetime','Activity',width: 220);
        }
        if ($table === 'leads') {
            $add('archived_at','Archived',"$t.archived_at",'datetime','Activity',width: 220);
        }

        return $c;
    }

    public function defaults(string $table): array
    {
        return match ($table) {
            'leads' => ['title', 'contact', 'company', 'status', 'owner', 'priority', 'next_action_at'],'contacts' => ['name', 'email', 'phone', 'company'],'companies' => ['name', 'email', 'phone'],'tasks' => ['title', 'lead', 'assignee', 'due_at', 'priority', 'status'],default => []
        };
    }
}
