<?php

namespace Awove\Nonprofit\Programs;

use Awove\Core\Contracts\NavigationItem;
use Awove\Nonprofit\Contracts\NonprofitCapability;

class Capability implements NonprofitCapability
{
    public function key(): string
    {
        return 'programs';
    }

    public function available($u): bool
    {
        return Access::available($u) || Access::available($u, 'participants');
    }

    public function permissions(): array
    {
        $p = ['nonprofit.programs.attendance.manage', 'nonprofit.programs.documents.view', 'nonprofit.programs.documents.manage'];
        foreach (['nonprofit.programs', 'nonprofit.programs.participants'] as $prefix) {
            foreach (['view.own', 'view.all', 'create', 'edit.own', 'edit.all', 'assign', 'archive', 'export'] as $a) {
                $p[] = $prefix.'.'.$a;
            }
        }

        return $p;
    }

    public function navigation(): array
    {
        return [new NavigationItem('Programs', '/nonprofit/programs', 'nonprofit.programs.view.own', ['nonprofit.programs.view.all', 'nonprofit.programs.participants.view.own', 'nonprofit.programs.participants.view.all'])];
    }

    public function tableIds(): array
    {
        return array_map(fn ($kind) => 'nonprofit.programs-'.$kind, array_keys(Access::MODELS));
    }

    public function tableColumns(string $table, $u): array
    {
        return (new Tables)->columns($table, $u);
    }

    public function tableDefaults(string $table): array
    {
        return (new Tables)->defaults($table);
    }

    public function widgets($u): array
    {
        if (! Access::available($u)) {
            return [];
        }

        return [['id' => 'nonprofit.programs.follow-ups', 'label' => 'Program follow-ups due', 'value' => Access::query($u, 'feedback')->whereNull('archived_at')->whereNull('completed_on')->where('due_on', '<=', Records::today())->whereIn('program_id', Access::query($u, 'programs')->whereNull('archived_at')->select('id'))->count(), 'href' => '/nonprofit/programs?section=feedback&view=due', 'permission' => 'nonprofit.programs.view.own', 'anyPermissions' => ['nonprofit.programs.view.all']]];
    }

    public function reminderSources(): array
    {
        return [new FollowUpReminders];
    }
}
