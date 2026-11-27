<?php

namespace Awove\Finance\History;

use App\Models\User;
use Awove\Core\Access as CoreAccess;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Finance\Access as FinanceAccess;
use Illuminate\Database\Eloquent\Builder;

/** Finance scope AND every associated identity; never disclose a hidden counterpart's facts. */
final class Access
{
    public static function actor($actor, bool $write = false): User
    {
        $user = FinanceAccess::actor($actor, 'history');
        abort_unless($user->hasVerifiedEmail() && (! $write || $user->can('imports.manage') && $user->can('finance.history.import')), 403);

        return $user;
    }

    /** @return Builder<Record> */
    public static function scope($actor): Builder
    {
        $user = self::actor($actor);
        $query = FinanceAccess::scope(Record::query(), $user, 'history');
        $contacts = CoreAccess::identities($user, Contact::class)->select('crm_contacts.id');
        $companies = CoreAccess::identities($user, Company::class)->select('crm_companies.id');

        return $query->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('placement', 'internal')->doesntHave('identities'))
            ->when($user->can('finance.history.view.all'), fn ($q) => $q->orWhere(fn ($q) => $q->where('placement', 'unresolved')->doesntHave('identities')))
            ->orWhere(fn ($q) => $q->where('placement', 'linked')->has('identities')->whereDoesntHave('identities', fn ($ids) => $ids
                ->where(fn ($hidden) => $hidden->where(fn ($contact) => $contact->whereNotNull('contact_id')->whereNotIn('contact_id', $contacts))
                    ->orWhere(fn ($company) => $company->whereNotNull('company_id')->whereNotIn('company_id', $companies))))));
    }

    public static function view($actor, int $id, bool $lock = false): Record
    {
        return self::scope($actor)->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
    }
}
