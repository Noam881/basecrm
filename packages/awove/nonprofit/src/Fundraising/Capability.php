<?php

namespace Awove\Nonprofit\Fundraising;

use Awove\Core\Contracts\NavigationItem;
use Awove\Nonprofit\Contracts\NonprofitCapability;

class Capability implements NonprofitCapability
{
    public function key(): string
    {
        return 'fundraising';
    }

    public function available($u): bool
    {
        return Access::available($u);
    }

    public function permissions(): array
    {
        return array_map(fn ($p) => Access::PREFIX.$p, ['view.own', 'view.all', 'create', 'edit.own', 'edit.all', 'assign', 'archive', 'export', 'pledges.view', 'pledges.manage', 'receipts.view', 'receipts.manage']);
    }

    public function navigation(): array
    {
        return [new NavigationItem(__('Fundraising'), '/nonprofit/fundraising', Access::PREFIX.'view.own', [Access::PREFIX.'view.all'])];
    }

    public function tableIds(): array
    {
        return ['nonprofit.fundraising-constituents', 'nonprofit.fundraising-pledges'];
    }

    public function tableColumns(string $table, $u): array
    {
        return (new Tables)->columns($table, $u);
    }

    public function tableDefaults(string $table): array
    {
        return (new Tables)->defaults($table);
    }

    public function reminderSources(): array
    {
        return [new Reminders];
    }

    public function widgets($u): array
    {
        if (! Access::available($u)) {
            return [];
        }

        return [['id' => 'nonprofit.fundraising.actions', 'label' => __('Fundraising follow-ups due'), 'value' => Access::query($u)->whereNull('archived_at')->whereNotNull('next_action')->where('next_action_on', '<=', Records::today())->count(), 'href' => '/nonprofit/fundraising?view=due', 'permission' => Access::PREFIX.'view.own', 'anyPermissions' => [Access::PREFIX.'view.all']]];
    }
}
