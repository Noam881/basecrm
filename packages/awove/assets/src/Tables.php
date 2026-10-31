<?php

namespace Awove\Assets;

use Awove\Core\Services\IdentityRelationships;
use Awove\Core\Tables\Column;

class Tables
{
    public static function relationExpression($u, string $field, string $role): string
    {
        $ids = app(IdentityRelationships::class)->query($u, $role, true)->pluck('id')->all();
        if (! $ids) {
            return 'NULL';
        }

        return "(SELECT COALESCE(c.name,b.name) FROM crm_identity_relationships ir LEFT JOIN crm_contacts c ON c.id=ir.contact_id LEFT JOIN crm_companies b ON b.id=ir.company_id WHERE ir.id=$field AND ir.id IN (".implode(',', array_map('intval', $ids)).'))';
    }

    public function columns(string $id, $u): array
    {
        $u = $u?->fresh();
        if (! Access::available($u)) {
            return [];
        }
        $choices = fn ($options) => array_map(fn ($value, $label) => compact('value', 'label'), array_keys($options), array_values($options));
        $s = substr($id, 7);
        $table = match ($s) {
            'register' => 'assets_register','assignments' => 'assets_assignments','maintenance' => 'assets_maintenance',default => null
        };
        if (! $table) {
            return [];
        }
        $columns = [new Column('id', 'Record ID', "$table.id", 'number')];
        if ($s === 'register') {
            $columns = [...$columns, new Column('code', 'Asset ID', "$table.code"), new Column('name', 'Name', "$table.name", width: 260), new Column('type', 'Type', "$table.type"), new Column('location', 'Location', "$table.location"), new Column('owner', 'Responsible person', "(SELECT name FROM users WHERE users.id=$table.owner_id)"), new Column('status', 'Status', "$table.status", 'select', options: $choices(Records::statuses())), new Column('serial_number', 'Serial number', "$table.serial_number"), new Column('purchased_on', 'Purchase date', "$table.purchased_on", 'date'), new Column('notes', 'Notes', "$table.notes", width: 300)];
        } else {
            $columns = [...$columns, new Column('asset', 'Asset', "(SELECT name FROM assets_register WHERE assets_register.id=$table.asset_id)", width: 240), new Column('asset_code', 'Asset ID', "(SELECT code FROM assets_register WHERE assets_register.id=$table.asset_id)")];
            if ($s === 'assignments') {
                $columns = [...$columns, new Column('target_type', 'Assignment to', "$table.target_type", 'select', options: $choices(['employee' => 'Employee', 'customer' => 'Customer', 'location' => 'Location'])), new Column('location', 'Assigned location', "$table.location"), new Column('assigned_on', 'Assignment date', "$table.assigned_on", 'date'), new Column('expected_return_on', 'Expected return', "$table.expected_return_on", 'date'), new Column('returned_on', 'Actual return', "$table.returned_on", 'date'), new Column('return_condition', 'Return condition', "$table.return_condition", width: 280), new Column('notes', 'Notes', "$table.notes", width: 280)];
                if ($u->can('employees.references.view')) {
                    $columns[] = new Column('employee', 'Employee', "(SELECT name FROM crm_employee_references WHERE crm_employee_references.id=$table.employee_reference_id)");
                }
                if ($u->can('identities.classifications.view') || $u->can('identities.classifications.manage')) {
                    $columns[] = new Column('customer', 'Customer', self::relationExpression($u, "$table.customer_relationship_id", 'customer'));
                }
            } else {
                $columns = [...$columns, new Column('action', 'Maintenance action', "$table.action", width: 280), new Column('description', 'Description', "$table.description", width: 300), new Column('owner', 'Responsible staff', "(SELECT name FROM users WHERE users.id=$table.owner_id)"), new Column('due_on', 'Due date', "$table.due_on", 'date'), new Column('status', 'Status', "$table.status", 'select', options: $choices(Records::maintenanceStatuses())), new Column('completed_on', 'Completion date', "$table.completed_on", 'date'), new Column('next_due_on', 'Manual next due date', "$table.next_due_on", 'date')];
                if ($u->can('identities.classifications.view') || $u->can('identities.classifications.manage')) {
                    $columns[] = new Column('supplier', 'Supplier', self::relationExpression($u, "$table.supplier_relationship_id", 'supplier'));
                }
                if ($u->can('assets.costs.view')) {
                    $columns = [...$columns, new Column('cost', 'Recorded cost', "(SELECT amount FROM assets_maintenance_costs WHERE maintenance_id=$table.id ORDER BY id DESC LIMIT 1)", 'number'), new Column('currency', 'Recorded currency', "(SELECT currency FROM assets_maintenance_costs WHERE maintenance_id=$table.id ORDER BY id DESC LIMIT 1)")];
                }
            }
        }
        if ($s === 'register' && $u->can('assets.documents.view')) {
            $columns[] = new Column('documents_count', 'Current documents', "(SELECT COUNT(*) FROM assets_documents WHERE asset_id=$table.id AND archived_at IS NULL)", 'number');
        }

        return [...$columns, new Column('created_at', 'Created', "$table.created_at", 'datetime')];
    }

    public function defaults(string $id): array
    {
        return match ($id) {
            'assets.register' => ['code', 'name', 'type', 'location', 'owner', 'status'],'assets.assignments' => ['asset', 'target_type', 'employee', 'customer', 'location', 'assigned_on', 'expected_return_on', 'returned_on'],'assets.maintenance' => ['action', 'asset', 'owner', 'due_on', 'status', 'next_due_on'],default => []
        };
    }
}
