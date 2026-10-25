<?php

namespace Awove\Marketing;

use Awove\Core\Modules;
use Awove\Core\Tables\Column;

class Tables
{
    public function columns(string $table, $u): array
    {
        $reports = $table === 'marketing.reports';
        $kind = $table === 'marketing.content' ? 'content' : 'campaigns';
        $t = $reports ? 'marketing_performance_reports' : ($kind === 'content' ? 'marketing_content' : 'marketing_campaigns');
        if ($reports) {
            return [new Column('campaign', 'Campaign', "(SELECT name FROM marketing_campaigns c WHERE c.id=$t.campaign_id)", width: 240), new Column('campaign_id', 'Campaign ID', "$t.campaign_id", 'number'), new Column('starts_on', 'Period start', "$t.starts_on", 'date'), new Column('ends_on', 'Period end', "$t.ends_on", 'date'), new Column('actual_spend', 'Actual spend', "$t.actual_spend", 'number'), new Column('currency', 'Currency', "$t.currency"), new Column('reported_leads', 'Reported leads', "$t.reported_leads", 'number'), new Column('cost_per_lead', 'Cost per lead', "ROUND($t.actual_spend / NULLIF($t.reported_leads,0),2)", 'number'), new Column('notes', 'Notes', "$t.notes", width: 260), new Column('created_at', 'Created', "$t.created_at", 'datetime'), new Column('version', 'Version', "$t.version", 'number')];
        }
        $c = [new Column($kind === 'content' ? 'title' : 'name', $kind === 'content' ? 'Title' : 'Campaign', "$t.".($kind === 'content' ? 'title' : 'name'), width: 260), new Column('channel', 'Channel', "$t.channel"), new Column('status', 'Status', "$t.status", 'select', options: array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(Labels::statuses($kind)), array_values(Labels::statuses($kind)))), new Column('owner', 'Owner', "(SELECT name FROM users WHERE users.id=$t.owner_id)"), new Column('owner_id', 'Owner ID', "$t.owner_id", 'number')];
        if ($kind === 'campaigns') {
            $c = [...$c, new Column('objective', 'Objective', "$t.objective", width: 280), new Column('starts_on', 'Start date', "$t.starts_on", 'date'), new Column('ends_on', 'End date', "$t.ends_on", 'date'), new Column('planned_budget', 'Planned budget', "$t.planned_budget", 'number'), new Column('currency', 'Currency', "$t.currency"), new Column('materials_url', 'Materials link', "$t.materials_url"), new Column('landing_url', 'Landing page', "$t.landing_url")];
        } else {
            $c = [...$c, new Column('format', 'Format', "$t.format"), new Column('planned_on', 'Planned publication', "$t.planned_on", 'date'), new Column('draft', 'Draft', "$t.draft", width: 300), new Column('asset_url', 'Asset link', "$t.asset_url"), new Column('publication_url', 'Publication link', "$t.publication_url"), new Column('published_on', 'Actual publication', "$t.published_on", 'date')];
        }

        return [...$c, new Column('created_at', 'Created', "$t.created_at", 'datetime'), new Column('updated_at', 'Updated', "$t.updated_at", 'datetime'), new Column('archived_at', 'Archived', "$t.archived_at", 'datetime'), new Column('version', 'Version', "$t.version", 'number')];
    }

    public function attribution($u): array
    {
        $u = $u?->fresh();
        if (! $u || ! $u->active || ! app(Modules::class)->enabled('marketing') || ! app(Modules::class)->enabled('sales') || ! ($u->can('marketing.attribution.view') || $u->can('marketing.attribution.edit'))) {
            return [];
        }
        $c = [];
        foreach (Labels::ATTRIBUTION as $key => $label) {
            $c[] = new Column('attribution:'.$key, $label, "(SELECT CASE WHEN jsonb_typeof(ma.payload->'$key')='string' THEN ma.payload->>'$key' ELSE NULL END FROM marketing_attributions ma WHERE ma.lead_id=crm_leads.id)", group: 'Attribution', width: $key === 'landing_page_url' ? 300 : 220);
        }
        $visible = app(Work::class)->campaigns($u)->select('id')->toRawSql();
        $c[] = new Column('module:marketing:campaign', 'Linked campaign', "(SELECT c.name FROM marketing_attributions ma JOIN marketing_campaigns c ON c.id=ma.linked_campaign_id WHERE ma.lead_id=crm_leads.id AND c.id IN ($visible))", group: 'Marketing', width: 240);

        return $c;
    }
}
