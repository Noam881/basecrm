<?php

namespace Tests\Fixtures;

use Awove\Core\Contracts\Module;
use Awove\Core\Contracts\RecordTableContributor;
use Awove\Core\Tables\Column;

/** Test-only contribution: no production module, package or business schema is introduced. */
class RecordTableModule implements Module, RecordTableContributor
{
    public function id(): string
    {
        return 'fixture-records';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function contract(): int
    {
        return 1;
    }

    public function dependencies(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return ['fixture-records.view', 'fixture-records.sensitive'];
    }

    public function navigation(): array
    {
        return [];
    }

    public function panels(): array
    {
        return [];
    }

    public function tableIds(): array
    {
        return ['fixture-records.contacts'];
    }

    public function tableColumns(string $table, $user): array
    {
        if (! $user->can('fixture-records.view')) {
            return [];
        }
        $columns = [new Column('name', 'Name', 'crm_contacts.name')];
        if ($user->can('fixture-records.sensitive')) {
            $columns[] = new Column('email', 'Email', 'crm_contacts.email');
        }

        return $columns;
    }

    public function tableDefaults(string $table): array
    {
        return ['email', 'name'];
    }
}
