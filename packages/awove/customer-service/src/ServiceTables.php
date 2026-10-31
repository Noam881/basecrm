<?php

namespace Awove\CustomerService;

use Awove\Core\Access as IdentityAccess;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Tables\Column;

class ServiceTables
{
    /** @return list<Column> */
    public function columns($user): array
    {
        if (! Access::available($user)) {
            return [];
        }
        $table = 'service_requests';
        $identity = function (string $model, string $foreignKey, string $field) use ($user, $table): string {
            $query = IdentityAccess::identities($user, $model);
            $identityTable = $query->getModel()->getTable();

            return '('.$query->select($identityTable.'.'.$field)->whereColumn($identityTable.'.id', $table.'.'.$foreignKey)->toRawSql().')';
        };
        $person = fn (string $foreignKey) => '(SELECT name FROM users WHERE users.id = '.$table.'.'.$foreignKey.')';
        $choices = fn (array $labels) => array_map(fn ($value, $label) => compact('value', 'label'), array_keys($labels), array_values($labels));
        $statuses = array_replace(ServiceController::STATUSES, array_intersect_key(Installation::manifest()['customer_service_statuses'] ?? [], ServiceController::STATUSES));
        $columns = [
            new Column('id', 'Request ID', "$table.id", 'number'),
            new Column('subject', 'Subject', "$table.subject", width: 300),
            new Column('description', 'Description', "$table.description", width: 320),
            new Column('status', 'Status', "$table.status", 'select', 'Workflow', $choices($statuses)),
            new Column('priority', 'Priority', "$table.priority", 'select', 'Workflow', $choices(['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'])),
            new Column('owner', 'Owner', $person('owner_id'), group: 'Ownership'),
            new Column('owner_id', 'Owner ID', "$table.owner_id", 'number', 'Ownership'),
            new Column('created_by', 'Created by', $person('created_by'), group: 'Ownership'),
            new Column('channel', 'Channel', "$table.channel", 'select', 'Intake', $choices(ServiceController::CHANNELS)),
            new Column('received_on', 'Date received', "$table.received_on", 'date', 'Intake'),
            new Column('next_action', 'Next action', "$table.next_action", group: 'Workflow', width: 280),
            new Column('due_on', 'Due date', "$table.due_on", 'date', 'Workflow'),
            new Column('resolution', 'Resolution', "$table.resolution", group: 'Resolution', width: 300),
            new Column('resolved_on', 'Resolution date', "$table.resolved_on", 'date', 'Resolution'),
            new Column('document_url', 'Supporting document link', "$table.document_url", group: 'Resolution', width: 300),
            new Column('history_count', 'History entries', "(SELECT COUNT(*) FROM service_history WHERE request_id=$table.id)", 'number', 'History'),
            new Column('created_at', 'Created', "$table.created_at", 'datetime', 'History'),
            new Column('updated_at', 'Last updated', "$table.updated_at", 'datetime', 'History'),
            new Column('archived_at', 'Archived', "$table.archived_at", 'datetime', 'History'),
            new Column('version', 'Record version', "$table.version", 'number', 'History'),
        ];
        foreach (['contact' => Contact::class, 'company' => Company::class] as $type => $model) {
            foreach (['id', 'name', 'email', 'phone'] as $field) {
                $id = $field === 'name' ? $type : $type.'_'.$field;
                $columns[] = new Column($id, ucfirst($type).' '.($field === 'id' ? 'ID' : ucfirst($field)), $identity($model, $type.'_id', $field), $field === 'id' ? 'number' : 'text', 'Customer', width: $field === 'email' ? 240 : 200);
            }
        }

        return $columns;
    }

    public function defaults(): array
    {
        return ['subject', 'contact', 'company', 'status', 'owner', 'priority', 'next_action', 'due_on'];
    }
}
