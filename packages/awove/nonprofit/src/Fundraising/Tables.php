<?php

namespace Awove\Nonprofit\Fundraising;

use Awove\Core\Tables\Column;

class Tables
{
    public function columns(string $id, $u): array
    {
        $u = $u?->fresh();
        if (! Access::available($u)) {
            return [];
        }
        $name = '(SELECT COALESCE(c.name,b.name) FROM crm_identity_relationships ir LEFT JOIN crm_contacts c ON c.id=ir.contact_id LEFT JOIN crm_companies b ON b.id=ir.company_id WHERE ir.id=nonprofit_constituents.relationship_id)';
        if ($id === 'nonprofit.fundraising-constituents') {
            return [new Column('id', 'Record ID', 'nonprofit_constituents.id', 'number'), new Column('name', 'Name', $name, width: 260), new Column('role', 'Classification', '(SELECT role FROM crm_identity_relationships WHERE id=nonprofit_constituents.relationship_id)', 'select', options: array_map(fn ($r) => ['value' => $r, 'label' => ucfirst($r)], Access::ROLES)), new Column('owner', 'Responsible person', '(SELECT name FROM users WHERE users.id=nonprofit_constituents.owner_id)'), new Column('next_action', 'Next action', 'nonprofit_constituents.next_action', width: 300), new Column('next_action_on', 'Next action date', 'nonprofit_constituents.next_action_on', 'date'), new Column('notes', 'Notes', 'nonprofit_constituents.notes'), new Column('created_at', 'Created', 'nonprofit_constituents.created_at', 'datetime')];
        }
        if ($id !== 'nonprofit.fundraising-pledges' || ! $u->can(Access::PREFIX.'pledges.view')) {
            return [];
        }

        return [new Column('id', 'Pledge ID', 'nonprofit_pledges.id', 'number'), new Column('reference', 'Reference', 'nonprofit_pledges.reference'), new Column('name', 'Name', "(SELECT ($name) FROM nonprofit_constituents WHERE id=nonprofit_pledges.constituent_id)", width: 260), new Column('amount', 'Pledged amount', 'nonprofit_pledges.amount', 'number'), new Column('currency', 'Retained currency', 'nonprofit_pledges.currency'), new Column('expected_on', 'Expected date', 'nonprofit_pledges.expected_on', 'date'), new Column('restrictions', 'Donation restrictions', 'nonprofit_pledges.restrictions', width: 300), new Column('created_at', 'Created', 'nonprofit_pledges.created_at', 'datetime')];
    }

    public function defaults(string $id): array
    {
        return $id === 'nonprofit.fundraising-constituents' ? ['name', 'role', 'owner', 'next_action', 'next_action_on'] : ['reference', 'name', 'amount', 'currency', 'expected_on'];
    }
}
