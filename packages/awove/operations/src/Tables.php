<?php

namespace Awove\Operations;

use Awove\Core\Access as IdentityAccess;
use Awove\Core\Models\Contact;
use Awove\Core\Services\IdentityRelationships;
use Awove\Core\Tables\Column;

class Tables
{
    public function classification($user, string $role, string $foreignKey, string $field = 'name'): string
    {
        $visible = app(IdentityRelationships::class)->query($user, $role, true)->select('crm_identity_relationships.id')->toRawSql();
        $value = $field === 'id' ? 'ir.id' : "CASE WHEN ir.contact_id IS NOT NULL THEN c.$field ELSE b.$field END";

        return "(SELECT $value FROM crm_identity_relationships ir LEFT JOIN crm_contacts c ON c.id=ir.contact_id LEFT JOIN crm_companies b ON b.id=ir.company_id WHERE ir.id=$foreignKey AND ir.id IN ($visible))";
    }

    public function columns($user, string $table): array
    {
        if (! Access::available($user)) {
            return [];
        }
        $owner = fn ($foreignKey) => "(SELECT name FROM users WHERE users.id=$foreignKey)";
        $choices = fn ($labels) => array_map(fn ($value, $label) => compact('value', 'label'), array_keys($labels), array_values($labels));
        $columns = [];
        if ($table === 'operations.requests') {
            $base = 'crm_work_items';
            foreach (['id' => 'Request ID', 'title' => 'Title', 'description' => 'Description'] as $field => $label) {
                $columns[] = new Column($field, $label, "$base.$field", $field === 'id' ? 'number' : 'text', width: $field === 'description' ? 300 : 240);
            }
            $columns[] = new Column('status', 'Status', "$base.status", 'select', 'Workflow', $choices(OperationsModule::requestStatuses()));
            $columns[] = new Column('priority', 'Priority', "$base.priority", 'select', 'Workflow', $choices(['low' => 'Low', 'normal' => 'Normal', 'high' => 'High']));
            $columns[] = new Column('due_on', 'Due date', "$base.due_on", 'date', 'Schedule');
            $columns[] = new Column('completed_on', 'Completion date', "$base.completed_on", 'date', 'Schedule');
            $columns[] = new Column('customer', 'Customer', $this->classification($user, 'customer', 'operations_request_details.customer_relationship_id'), 'text', 'Customer', width: 240);
            $columns[] = new Column('customer_id', 'Customer classification ID', $this->classification($user, 'customer', 'operations_request_details.customer_relationship_id', 'id'), 'number', 'Customer');
            $columns[] = new Column('notes', 'Notes', 'operations_request_details.notes', 'text', 'Details', width: 300);
            $columns[] = new Column('document_url', 'Document link', 'operations_request_details.document_url', 'text', 'Details', width: 300);
        } elseif ($table === 'operations.orders') {
            $base = 'operations_orders';
            $columns = [new Column('id', 'Order ID', "$base.id", 'number'), new Column('reference', 'Reference', "$base.reference", width: 220), new Column('description', 'Description', "$base.description", width: 300), new Column('supplier', 'Supplier', $this->classification($user, 'supplier', "$base.supplier_relationship_id"), 'text', 'Supplier', width: 240), new Column('supplier_id', 'Supplier classification ID', $this->classification($user, 'supplier', "$base.supplier_relationship_id", 'id'), 'number', 'Supplier'), new Column('status', 'Status', "$base.status", 'select', 'Delivery', $choices(OperationsModule::orderStatuses())), new Column('quantity', 'Agreed quantity', "$base.quantity", 'number', 'Delivery'), new Column('received_quantity', 'Received quantity', "(SELECT SUM(quantity) FROM operations_order_receipts WHERE order_id=$base.id)", 'number', 'Delivery'), new Column('outstanding_quantity', 'Outstanding quantity', "($base.quantity-COALESCE((SELECT SUM(quantity) FROM operations_order_receipts WHERE order_id=$base.id),0))", 'number', 'Delivery'), new Column('agreed_total', 'Agreed total', "$base.agreed_total", 'number', 'Agreement'), new Column('currency', 'Currency', "$base.currency", 'text', 'Agreement'), new Column('ordered_on', 'Order date', "$base.ordered_on", 'date', 'Schedule'), new Column('expected_on', 'Expected delivery', "$base.expected_on", 'date', 'Schedule'), new Column('received_on', 'Receipt completion date', "$base.received_on", 'date', 'Schedule'), new Column('outstanding_items', 'Outstanding items', "$base.outstanding_items", 'text', 'Delivery', width: 300), new Column('document_url', 'Document link', "$base.document_url", 'text', 'Agreement', width: 300)];
        } elseif ($table === 'operations.routines') {
            $base = 'crm_routines';
            $columns = [new Column('id', 'Routine ID', "$base.id", 'number'), new Column('name', 'Name', "$base.name", width: 260), new Column('frequency', 'Frequency', "$base.frequency"), new Column('next_due_on', 'Next occurrence', "$base.next_due_on", 'date', 'Schedule'), new Column('checklist', 'Checklist', "$base.checklist::text", 'text', 'Routine', width: 300), new Column('completions', 'Completed occurrences', "(SELECT COUNT(*) FROM crm_routine_completions WHERE routine_id=$base.id)", 'number', 'Routine')];
        } elseif ($table === 'operations.directory') {
            if (! $user->can('identities.classifications.view') && ! $user->can('identities.classifications.manage')) {
                return [];
            }
            $base = 'crm_identity_relationships';
            $identity = fn ($field) => "(CASE WHEN $base.contact_id IS NOT NULL THEN (SELECT $field FROM crm_contacts WHERE id=$base.contact_id) ELSE (SELECT $field FROM crm_companies WHERE id=$base.company_id) END)";
            $main = IdentityAccess::identities($user, Contact::class)->select('name')->whereColumn('crm_contacts.id', "$base.main_contact_id")->toRawSql();
            $columns = [new Column('id', 'Classification ID', "$base.id", 'number'), new Column('name', 'Name', $identity('name'), width: 260), new Column('role', 'Classification', "$base.role", 'select', 'Directory', $choices(['customer' => 'Customer', 'supplier' => 'Supplier'])), new Column('phone', 'Phone', $identity('phone')), new Column('email', 'Email', $identity('email'), width: 250), new Column('main_contact', 'Main contact', "($main)"), new Column('responsible', 'Responsible person', $owner("$base.responsible_id")), new Column('active', 'Active', "$base.active", 'boolean'), new Column('notes', 'Notes', "$base.notes", width: 300)];
        } else {
            return [];
        }
        if ($table !== 'operations.directory') {
            $columns[] = new Column('owner', 'Owner', $owner("$base.owner_id"), 'text', 'Ownership');
            $columns[] = new Column('owner_id', 'Owner ID', "$base.owner_id", 'number', 'Ownership');
        }
        foreach (['created_at' => 'Created', 'updated_at' => 'Last updated', 'archived_at' => 'Archived'] as $field => $label) {
            $columns[] = new Column($field, $label, "$base.$field", 'datetime', 'History');
        }
        $columns[] = new Column('version', 'Record version', "$base.version", 'number', 'History');

        return $columns;
    }

    public function defaults(string $table): array
    {
        return match ($table) {
            'operations.requests' => ['title', 'customer', 'status', 'owner', 'priority', 'due_on'],'operations.orders' => ['reference', 'supplier', 'status', 'quantity', 'outstanding_quantity', 'agreed_total', 'expected_on'],'operations.routines' => ['name', 'owner', 'frequency', 'next_due_on', 'completions'],'operations.directory' => ['name', 'role', 'phone', 'email', 'main_contact', 'responsible', 'active'],default => []
        };
    }
}
