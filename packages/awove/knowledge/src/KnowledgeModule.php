<?php

namespace Awove\Knowledge;

use Awove\Core\Contracts\DashboardContributor;
use Awove\Core\Contracts\Module;
use Awove\Core\Contracts\NavigationItem;
use Awove\Core\Contracts\RecordTableContributor;
use Awove\Core\Contracts\ReminderContributor;
use Awove\Core\Contracts\WorkReferenceContributor;
use Awove\Core\Installation;
use Awove\Core\Models\Routine;
use Awove\Core\Models\WorkItem;

class KnowledgeModule implements DashboardContributor, Module, RecordTableContributor, ReminderContributor, WorkReferenceContributor
{
    public function id(): string
    {
        return 'knowledge';
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
        $p = ['knowledge.documents.view', 'knowledge.documents.manage', 'knowledge.systems.references.view', 'knowledge.systems.references.edit'];
        foreach (['library', 'renewals', 'systems'] as $s) {
            foreach (['view.own', 'view.all', 'create', 'edit.own', 'edit.all', 'assign', 'archive', 'export'] as $a) {
                $p[] = 'knowledge.'.$s.'.'.$a;
            }
        }

        return $p;
    }

    public function navigation(): array
    {
        return [new NavigationItem(Installation::manifest()['module_labels']['knowledge'] ?? 'Knowledge', '/knowledge', 'knowledge.library.view.own', ['knowledge.library.view.all', 'knowledge.renewals.view.own', 'knowledge.renewals.view.all', 'knowledge.systems.view.own', 'knowledge.systems.view.all'])];
    }

    public function panels(): array
    {
        return [];
    }

    public function tableIds(): array
    {
        return ['knowledge.library', 'knowledge.renewals', 'knowledge.systems'];
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
        return [new DueReminders];
    }

    public function workReferences($user, WorkItem|Routine $record): array
    {
        return app(Instructions::class)->forWork($user, $record);
    }

    public function widgets($u): array
    {
        $items = [];
        if (Access::available($u, 'library')) {
            $items[] = ['id' => 'knowledge.current', 'label' => 'Current knowledge materials', 'value' => Access::query($u, 'library')->whereNull('archived_at')->where('status', 'current')->count(), 'href' => '/knowledge?section=library&view=current', 'permission' => 'knowledge.library.view.own', 'anyPermissions' => ['knowledge.library.view.all']];
        }if (Access::available($u, 'renewals')) {
            $items[] = ['id' => 'knowledge.renewals', 'label' => 'Renewals due in the next 30 days', 'value' => Access::query($u, 'renewals')->whereNull('archived_at')->where('status', '!=', 'renewed')->whereBetween('expires_on', [now(Installation::settings()['timezone'])->toDateString(), now(Installation::settings()['timezone'])->addDays(30)->toDateString()])->count(), 'href' => '/knowledge?section=renewals&view=upcoming', 'permission' => 'knowledge.renewals.view.own', 'anyPermissions' => ['knowledge.renewals.view.all']];
        }

        return $items;
    }
}
