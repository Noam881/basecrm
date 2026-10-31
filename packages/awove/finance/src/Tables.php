<?php

namespace Awove\Finance;

use Awove\Core\Services\IdentityRelationships;
use Awove\Core\Tables\Column;

class Tables
{
    public static function paidExpression(): string
    {
        return '(SELECT COALESCE(SUM(fp.amount),0) FROM finance_payments fp WHERE fp.record_id=finance_records.id)';
    }

    public static function remainingExpression(): string
    {
        return '(finance_records.total - '.self::paidExpression().')';
    }

    public static function statusExpression(): string
    {
        return "(CASE WHEN finance_records.cancelled_at IS NOT NULL THEN 'cancelled' WHEN ".self::paidExpression()." = 0 THEN 'unpaid' WHEN ".self::remainingExpression()." = 0 THEN 'paid' ELSE 'part-paid' END)";
    }

    public function columns($user, string $cap): array
    {
        $visible = app(IdentityRelationships::class)->query($user, Ledger::role($cap), true)->select('crm_identity_relationships.id')->toRawSql();
        $identity = "(SELECT COALESCE(c.name,co.name) FROM crm_identity_relationships ir LEFT JOIN crm_contacts c ON c.id=ir.contact_id LEFT JOIN crm_companies co ON co.id=ir.company_id WHERE ir.id=finance_records.relationship_id AND ir.id IN ($visible))";
        $id = "(CASE WHEN finance_records.relationship_id IN ($visible) THEN finance_records.relationship_id ELSE NULL END)";
        $columns = [new Column('relationship', $cap === 'collections' ? 'Customer' : 'Supplier', $identity, width: 220), new Column('relationship_id', 'Classification ID', $id, 'number'), new Column('reference', 'Reference', 'finance_records.reference', width: 200), new Column('description', 'Description', 'finance_records.description', width: 280), new Column('total', 'Total payable', 'finance_records.total', 'number'), new Column('paid_amount', 'Recorded payments', self::paidExpression(), 'number'), new Column('remaining_amount', 'Remaining balance', self::remainingExpression(), 'number'), new Column('currency', 'Currency', 'finance_records.currency'), new Column('due_on', 'Due date', 'finance_records.due_on', 'date'), new Column('status', 'Status', self::statusExpression(), 'select', options: array_map(fn ($key, $label) => ['value' => $key, 'label' => $label], array_keys(Context::statuses()), array_values(Context::statuses()))), new Column('owner', 'Owner', '(SELECT name FROM users WHERE users.id=finance_records.owner_id)'), new Column('owner_id', 'Owner ID', 'finance_records.owner_id', 'number'), new Column('document_url', 'Document link', 'finance_records.document_url'), new Column('created_at', 'Created', 'finance_records.created_at', 'datetime'), new Column('updated_at', 'Updated', 'finance_records.updated_at', 'datetime'), new Column('cancelled_at', 'Cancelled', 'finance_records.cancelled_at', 'datetime'), new Column('version', 'Version', 'finance_records.version', 'number')];
        if ($cap === 'expenses') {
            $columns[] = new Column('category', 'Category', 'finance_records.category');
        } else {
            $columns = [...$columns, new Column('next_action', 'Next collection action', 'finance_records.next_action', width: 260), new Column('next_action_on', 'Next action date', 'finance_records.next_action_on', 'date')];
        }

        return $columns;
    }
}
