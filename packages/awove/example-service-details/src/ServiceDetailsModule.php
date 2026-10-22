<?php

namespace Awove\ServiceDetails;

use Awove\Core\Contracts\LeadPanel;
use Awove\Core\Contracts\Module;

class ServiceDetailsModule implements Module
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
        return [];
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
        return [new LeadPanel('service-details', 'Service details', 'ServiceDetails/Panel', '/modules/service-details/leads/{lead}', 'service-details.view')];
    }
}
