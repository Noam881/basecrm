<?php

namespace Awove\Core\Policies;

use Awove\Core\Models\Lead;
use Awove\Core\Modules;

class LeadPolicy
{
    public function view($u, Lead $lead): bool
    {
        return app(Modules::class)->enabled('sales') && $u->active && ($u->can('leads.view.all') || ($u->can('leads.view.own') && $lead->owner_id === $u->id));
    }

    public function update($u, Lead $lead): bool
    {
        return $this->view($u, $lead) && ! $lead->archived_at && ($u->can('leads.edit.all') || ($u->can('leads.edit.own') && $lead->owner_id === $u->id));
    }

    public function archive($u, Lead $lead): bool
    {
        return $this->update($u, $lead) && $u->can('records.archive');
    }
}
