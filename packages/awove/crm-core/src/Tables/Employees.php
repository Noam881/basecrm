<?php

namespace Awove\Core\Tables;

use App\Models\User;
use Awove\Core\Access;
use Awove\Core\Models\Contact;
use Awove\Core\Services\EmployeeReferences;

/** Core utility table; it is available independently of every business module. */
class Employees
{
    /** @return array<string,Column> */
    public function columns($user): array
    {
        abort_unless(app(EmployeeReferences::class)->available($user), 403);
        $user = $user->fresh();
        $base = 'crm_employee_references';
        $contact = Access::identities($user, Contact::class)->select('name')->whereColumn('crm_contacts.id', "$base.contact_id")->toRawSql();
        $account = User::select('name')->when(! $user->can('users.manage'), fn ($q) => $q->whereKey($user->id))->whereColumn('users.id', "$base.user_id")->toRawSql();
        $columns = [new Column('id', 'Employee reference ID', "$base.id", 'number'), new Column('name', 'Employee', "$base.name", width: 280), new Column('active', 'Active', "$base.active", 'boolean'), new Column('contact', 'Linked contact', "($contact)", group: 'Optional links', width: 240), new Column('account', 'Linked account', "($account)", group: 'Optional links', width: 240), new Column('created_at', 'Created', "$base.created_at", 'datetime'), new Column('updated_at', 'Last updated', "$base.updated_at", 'datetime'), new Column('version', 'Record version', "$base.version", 'number')];

        return array_combine(array_map(fn ($column) => $column->id, $columns), $columns);
    }

    public function defaults(): array
    {
        return ['name', 'active', 'contact', 'account'];
    }
}
