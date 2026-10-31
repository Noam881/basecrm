<?php

namespace Awove\Finance\Payroll;

use Awove\Core\Services\EmployeeReferences;
use Awove\Core\Tables\Column;

class Tables
{
    public function columns($user): array
    {
        $visible = app(EmployeeReferences::class)->query($user)->select('id')->toRawSql();
        $p = 'finance_payroll_preparations.';

        return [new Column('employee', 'Employee', "(SELECT name FROM crm_employee_references WHERE id={$p}employee_reference_id AND id IN ($visible))", width: 220), new Column('period_start_on', 'Period start', $p.'period_start_on', 'date'), new Column('period_end_on', 'Period end', $p.'period_end_on', 'date'), new Column('status', 'Status', $p.'status', 'select', options: array_map(fn ($key, $label) => ['value' => $key, 'label' => $label], array_keys(Preparations::statuses()), array_values(Preparations::statuses()))), new Column('owner', 'Owner', '(SELECT name FROM users WHERE id='.$p.'owner_id)'), new Column('sent_on', 'Sent date', $p.'sent_on', 'date'), new Column('currency', 'Currency', $p.'currency'), new Column('notes', 'Notes', $p.'notes', width: 260), new Column('inputs', 'Manual inputs', $p.'inputs', width: 260), new Column('created_at', 'Created', $p.'created_at', 'datetime'), new Column('updated_at', 'Updated', $p.'updated_at', 'datetime'), new Column('archived_at', 'Archived', $p.'archived_at', 'datetime')];
    }
}
