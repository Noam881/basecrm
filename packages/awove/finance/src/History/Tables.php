<?php

namespace Awove\Finance\History;

use Awove\Core\Tables\Column;

class Tables
{
    public function columns(): array
    {
        $p = 'finance_history_records.';

        return [
            new Column('label', 'Reference', '(SELECT payload->>\'label\' FROM finance_history_entries WHERE record_id='.$p."id AND entry_kind='facts' ORDER BY id DESC LIMIT 1)", width: 280),
            new Column('kind', 'Record kind', $p.'kind', 'select', options: $this->options(['collection' => 'Collection reference', 'income' => 'Income reference', 'bonus' => 'Bonus reference', 'monthly-hours' => 'Monthly hours reference', 'ancillary-quote' => 'Ancillary quote reference', 'reference' => 'Other financial reference'])),
            new Column('placement', 'Association', $p.'placement', 'select', options: $this->options(['linked' => 'Linked identities', 'internal' => 'Internal office record', 'unresolved' => 'Association unresolved'])),
            new Column('owner', 'Owner', '(SELECT name FROM users WHERE id='.$p.'owner_id)'),
            new Column('created_at', 'Recorded in CRM', $p.'created_at', 'datetime'),
        ];
    }

    private function options(array $values): array
    {
        return array_map(fn ($value, $label) => ['value' => $value, 'label' => __($label)], array_keys($values), array_values($values));
    }
}
