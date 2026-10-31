<?php

namespace Awove\Marketing;

use Awove\Core\Contracts\DashboardContributor;
use Awove\Core\Contracts\LeadPanel;
use Awove\Core\Contracts\LeadTableContributor;
use Awove\Core\Contracts\Module;
use Awove\Core\Contracts\NavigationItem;
use Awove\Core\Contracts\RecordTableContributor;
use Awove\Core\Contracts\ReminderContributor;
use Awove\Core\Installation;
use Awove\Core\Modules;

class MarketingModule implements DashboardContributor, LeadTableContributor, Module, RecordTableContributor, ReminderContributor
{
    public function id(): string
    {
        return 'marketing';
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
        $p = ['marketing.attribution.view', 'marketing.attribution.edit'];
        foreach (['campaigns', 'content'] as $k) {
            foreach (['view.own', 'view.all', 'create', 'edit.own', 'edit.all', 'assign', 'archive', 'export'] as $s) {
                $p[] = 'marketing.'.$k.'.'.$s;
            }
        }

        return $p;
    }

    public function navigation(): array
    {
        return [new NavigationItem(Installation::manifest()['module_labels']['marketing'] ?? __('Marketing'), '/marketing', 'marketing.campaigns.view.own', ['marketing.campaigns.view.all', 'marketing.content.view.own', 'marketing.content.view.all'])];
    }

    public function panels(): array
    {
        return app(Modules::class)->enabled('sales') ? [new LeadPanel('marketing.attribution', __('Marketing attribution'), 'Marketing/Attribution', '/marketing/leads/{lead}/attribution', 'marketing.attribution.view', ['marketing.attribution.edit'])] : [];
    }

    public function tableIds(): array
    {
        return ['marketing.campaigns', 'marketing.reports', 'marketing.content'];
    }

    public function tableColumns(string $table, $u): array
    {
        $kind = $table === 'marketing.content' ? 'content' : 'campaigns';

        return Access::available($u, $kind) ? (new Tables)->columns($table, $u) : [];
    }

    public function tableDefaults(string $table): array
    {
        return match ($table) {
            'marketing.campaigns' => ['name', 'status', 'channel', 'owner', 'starts_on', 'ends_on', 'planned_budget'],'marketing.reports' => ['campaign', 'starts_on', 'ends_on', 'actual_spend', 'reported_leads', 'cost_per_lead'],default => ['title', 'status', 'channel', 'format', 'owner', 'planned_on']
        };
    }

    public function leadTableColumns($u): array
    {
        return (new Tables)->attribution($u);
    }

    public function widgets($u): array
    {
        $w = [];
        if (Access::available($u, 'campaigns')) {
            $w[] = ['id' => 'marketing.active-campaigns', 'label' => __('Active campaigns'), 'value' => app(Work::class)->campaigns($u)->whereNull('archived_at')->where('status', 'active')->count(), 'href' => '/marketing/campaigns?status=active', 'permission' => 'marketing.campaigns.view.own', 'anyPermissions' => ['marketing.campaigns.view.all']];
        }if (Access::available($u, 'content')) {
            $w[] = ['id' => 'marketing.content-due', 'label' => __('Content due'), 'value' => app(Work::class)->content($u)->whereNull('archived_at')->where('status', '<>', 'published')->where('planned_on', '<=', now(Installation::settings()['timezone'])->toDateString())->count(), 'href' => '/marketing/content?view=due', 'permission' => 'marketing.content.view.own', 'anyPermissions' => ['marketing.content.view.all']];
        }

        return $w;
    }

    public function reminderSources(): array
    {
        return [new ContentReminders];
    }
}
