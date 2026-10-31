<?php

namespace Awove\Core\Tables;

use Awove\Core\ContactDetails;
use Awove\Core\Contracts\LeadTableContributor;
use Awove\Core\Contracts\RecordTableContributor;
use Awove\Core\Installation;
use Awove\Core\Models\Status;
use Awove\Core\Modules;
use Awove\Core\StatusDisplay;

class Catalog
{
    /** @return array<string,Column> */
    public function columns(string $table, $user): array
    {
        abort_unless($user && $user->active, 403);
        if ($table === 'employees') {
            return app(Employees::class)->columns($user);
        }
        if (in_array($table, ['leads', 'tasks'], true)) {
            abort_unless(app(Modules::class)->enabled('sales'), 404);
            abort_unless($user->can('leads.view.own') || $user->can('leads.view.all'), 403);
        }
        if (! in_array($table, ['leads', 'contacts', 'companies', 'tasks'], true)) {
            $contributor = $this->contributors()[$table] ?? null;
            abort_unless($contributor !== null, 404);
            $columns = $contributor->tableColumns($table, $user);
            abort_if($columns === [], 403);
            $catalog = [];
            foreach ($columns as $column) {
                if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_:.-]{0,99}$/D', $column->id) || isset($catalog[$column->id])) {
                    throw new \RuntimeException('Module table fields must have unique, stable identifiers.');
                }
                $catalog[$column->id] = $column;
            }

            return $catalog;
        }
        $c = [];
        $add = function (string $id, string $label, string $sql, string $type = 'text', string $group = 'Record', array $options = [], int $width = 180) use (&$c) {
            $c[$id] = new Column($id, $label, $sql, $type, $group, $options, $width);
        };
        $person = fn (string $fk) => "(SELECT name FROM users WHERE users.id = $fk)";
        $priorities = [['value' => 'low', 'label' => __('Low')], ['value' => 'normal', 'label' => __('Normal')], ['value' => 'high', 'label' => __('High')]];
        $choices = fn (array $labels) => array_map(fn ($value, $label) => ['value' => $value, 'label' => (string) __($label)], array_keys($labels), array_values($labels));
        $availability = function (string $sql, array $labels): string {
            $cases = [];
            foreach ($labels as $value => $label) {
                $cases[] = "WHEN '".str_replace("'", "''", $value)."' THEN '".str_replace("'", "''", $label)."'";
            }

            return '(SELECT string_agg(CASE choice.value '.implode(' ', $cases)." END, ', ' ORDER BY choice.ordinality) FROM jsonb_array_elements_text(COALESCE(($sql)::jsonb, '[]'::jsonb)) WITH ORDINALITY AS choice(value, ordinality))";
        };
        $contactColumns = function (string $t, string $prefix = '', bool $related = false) use ($add, $choices, $availability): void {
            $expression = fn (string $field) => $related ? "(SELECT $field FROM crm_contacts WHERE crm_contacts.id=$t.contact_id)" : "$t.$field";
            $label = $related ? 'Contact ' : '';
            foreach (['secondary_phone' => 'Secondary phone', 'address' => 'Address', 'city' => 'City', 'country' => 'Country'] as $field => $name) {
                $add($prefix.$field, $label.$name, $expression($field), 'text', 'Contact details', width: $field === 'address' ? 280 : 200);
            }
            foreach (['secondary_phone_label' => ['Secondary phone type', ContactDetails::PHONE_LABELS], 'preferred_contact_method' => ['Preferred contact method', ContactDetails::METHODS], 'source' => ['How they heard about us', ContactDetails::SOURCES]] as $field => [$name, $labels]) {
                $add($prefix.$field, $label.$name, $expression($field), 'select', 'Contact details', $choices($labels), 220);
            }
            $add($prefix.'preferred_contact_times', $label.'Best contact time', $availability($expression('preferred_contact_times'), ContactDetails::TIMES), 'text', 'Contact details', width: 240);
            $add($prefix.'preferred_contact_days', $label.'Best contact days', $availability($expression('preferred_contact_days'), ContactDetails::DAYS), 'text', 'Contact details', width: 280);
            $contactId = $related ? "$t.contact_id" : "$t.id";
            $add($prefix.'notes_count', $label.'Notes', "(SELECT COUNT(*) FROM crm_contact_notes WHERE contact_id=$contactId)", 'number', 'Activity');
        };

        if ($table === 'leads') {
            $t = 'crm_leads';
            $add('id', 'Lead ID', "$t.id", 'number');
            $add('title', 'Enquiry title', "$t.title", width: 280);
            foreach (['contact' => 'crm_contacts', 'company' => 'crm_companies'] as $key => $related) {
                foreach (['name', 'email', 'phone'] as $field) {
                    $add($field === 'name' ? $key : $key.'_'.$field, ucfirst($key).' '.ucfirst($field), "(SELECT $field FROM $related WHERE $related.id=$t.{$key}_id)", 'text', 'Customer', width: $field === 'email' ? 240 : 200);
                }
            }
            $contactColumns($t, 'contact_', true);
            $add('owner', 'Owner', $person("$t.owner_id"), 'text', 'Ownership');
            $add('owner_id', 'Owner ID', "$t.owner_id", 'number', 'Ownership');
            $add('created_by', 'Created by', $person("$t.created_by"), 'text', 'Ownership');
            $add('status', 'Stage', "(SELECT name FROM crm_statuses WHERE id=$t.status_id)", 'select', 'Pipeline', Status::orderBy('position')->get()->map(fn ($s) => ['value' => $s->name, 'label' => StatusDisplay::label($s)])->all());
            $add('outcome', 'Stage outcome', "(SELECT kind FROM crm_statuses WHERE id=$t.status_id)", 'select', 'Pipeline', array_map(fn ($s) => ['value' => $s, 'label' => (string) __(ucfirst($s))], ['active', 'won', 'lost']));
            $add('priority', 'Priority', "$t.priority", 'select', 'Pipeline', $priorities, 140);
            $add('source', 'Source', "$t.source", group: 'Pipeline');
            $add('needs', 'Needs', "$t.needs", group: 'Sales', width: 280);
            $add('estimated_value', 'Estimated value', "$t.estimated_value", 'number', 'Sales');
            $add('closing_date', 'Closing date', "$t.closing_date", 'date', 'Sales');
            $add('final_value', 'Final deal value', "$t.final_value", 'number', 'Sales');
            $add('loss_reason', 'Loss reason', "$t.loss_reason", group: 'Sales', width: 280);
            $add('proposal', 'Proposal file', "(SELECT original_name FROM crm_proposals WHERE lead_id=$t.id ORDER BY version DESC LIMIT 1)", group: 'Proposals', width: 260);
            $add('proposal_count', 'Proposal versions', "(SELECT COUNT(*) FROM crm_proposals WHERE lead_id=$t.id)", 'number', 'Proposals');
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
                $add('custom:'.$key, $field['label'], $sql, $field['type'] === 'text' ? 'text' : $field['type'], __('Custom fields'), $options);
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
                $contactColumns($t);
            }
        } else {
            $t = 'crm_tasks';
            $add('id', 'Follow-up ID', "$t.id", 'number');
            $add('title', 'Follow-up', "$t.title", width: 280);
            $add('lead', 'Lead', "(SELECT title FROM crm_leads WHERE id=$t.lead_id)", 'text', 'Customer', width: 240);
            $add('assignee', 'Assignee', $person("$t.assignee_id"), 'text', 'Ownership');
            $add('priority', 'Priority', "$t.priority", 'select', 'Follow-up', $priorities);
            $add('status', 'Status', "$t.status", 'select', 'Follow-up', array_map(fn ($v) => ['value' => $v, 'label' => (string) __(ucfirst($v))], ['open', 'completed', 'cancelled']));
            $add('due_at', 'Deadline', "$t.due_at", 'datetime', 'Schedule', width: 220);
            $add('remind_at', 'Reminder', "$t.remind_at", 'datetime', 'Schedule', width: 220);
            $add('version', 'Record version', "$t.version", 'number', 'Activity');
        }
        foreach (['created_at' => 'Created', 'updated_at' => 'Last updated'] as $id => $label) {
            $add($id, $label, "$t.$id", 'datetime', 'Activity', width: 220);
        }
        if ($table === 'leads') {
            $add('archived_at', 'Archived', "$t.archived_at", 'datetime', 'Activity', width: 220);
        }

        return $c;
    }

    /** @return list<string> Only installed and enabled module table IDs are accepted by preferences. */
    public function tableIds(): array
    {
        $core = app(Modules::class)->enabled('sales') ? ['leads', 'contacts', 'companies', 'tasks'] : ['contacts', 'companies'];

        return [...$core, 'employees', ...array_keys($this->contributors())];
    }

    /** @return array<string,RecordTableContributor> */
    private function contributors(): array
    {
        $tables = [];
        foreach (app(Modules::class)->installed() as $id => $module) {
            if (! app(Modules::class)->enabled($id) || ! $module instanceof RecordTableContributor) {
                continue;
            }
            foreach ($module->tableIds() as $table) {
                if (! preg_match('/^'.preg_quote($id, '/').'\\.[a-z][a-z0-9-]{0,63}$/D', $table) || isset($tables[$table])) {
                    throw new \RuntimeException('Module table IDs must be unique and use the owning module namespace.');
                }
                $tables[$table] = $module;
            }
        }

        return $tables;
    }

    public function defaults(string $table): array
    {
        if ($table === 'employees') {
            return app(Employees::class)->defaults();
        }
        if (! in_array($table, ['leads', 'contacts', 'companies', 'tasks'], true)) {
            $contributor = $this->contributors()[$table] ?? null;
            abort_unless($contributor !== null, 404);

            return $contributor->tableDefaults($table);
        }

        return match ($table) {
            'leads' => ['contact', 'company', 'status', 'owner', 'contact_phone', 'contact_email', 'priority', 'next_action_at', 'created_at', 'proposal'],'contacts' => ['name', 'email', 'phone', 'company'],'companies' => ['name', 'email', 'phone'],default => ['title', 'lead', 'assignee', 'due_at', 'priority', 'status']
        };
    }
}
