<?php

namespace Awove\Core\Policies;

use Awove\Core\Access;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;

class IdentityPolicy
{
    public function view($user, Contact|Company $identity): bool
    {
        return Access::identities($user, $identity::class)->whereKey($identity->id)->exists();
    }

    public function update($user, Contact|Company $identity): bool
    {
        return $this->view($user, $identity) && ($user->can('identities.manage') || ($user->can('leads.edit.own') && $identity->getAttribute('created_by') === $user->id));
    }
}
