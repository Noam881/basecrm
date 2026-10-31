<?php

namespace Awove\ServiceDetails;

use Awove\Core\Contracts\LeadPanel;
use Awove\Core\Contracts\LeadTableContributor;
use Awove\Core\Contracts\Module;
use Awove\Core\Tables\Column;

class ServiceDetailsModule implements LeadTableContributor, Module
{
    public function id(): string
    {
        return 'service-details';
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
        return ['sales'];
    }

    public function permissions(): array
    {
        return ['service-details.view', 'service-details.edit'];
    }

    public function navigation(): array
    {
        return [];
    }

    public function panels(): array
    {
        return [new LeadPanel('service-details', __('Service details'), 'ServiceDetails/Panel', '/modules/service-details/leads/{lead}', 'service-details.view')];
    }

    public function leadTableColumns($user): array
    {
        if (! $user->can('service-details.view')) {
            return [];
        }

        return array_map(fn ($field) => new Column('module:service-details:'.$field, $field === 'service_reference' ? 'Service reference' : 'Requested service', "(SELECT $field FROM service_details_leads WHERE lead_id=crm_leads.id)", 'text', 'Service details', width: 240), ['service_reference', 'requested_service']);
    }
}
