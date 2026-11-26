<?php

namespace Awove\Core\Contracts;

use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;

interface IdentityPanelContributor extends Module
{
    /** @return list<IdentityPanel> */
    public function identityPanels($user, Contact|Company $identity): array;
}
