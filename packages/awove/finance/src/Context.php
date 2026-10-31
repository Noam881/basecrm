<?php

namespace Awove\Finance;

use Awove\Core\Installation;
use Awove\Core\Services\IdentityRelationships;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class Context
{
    public static function statuses(): array
    {
        $defaults = ['unpaid' => 'Unpaid', 'part-paid' => 'Part paid', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];
        $labels = array_replace(array_map(fn ($label) => (string) __($label), $defaults), array_intersect_key(Installation::manifest()['finance_statuses'] ?? [], $defaults));
        foreach ($labels as $label) {
            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 100) {
                throw new \RuntimeException('Finance status labels must be nonempty strings up to 100 characters.');
            }
        }

        return $labels;
    }

    public function options($user, string $cap): array
    {
        $user = Access::actor($user, $cap);
        $prefix = 'finance.'.$cap.'.';

        return ['cap' => $cap, 'sections' => app(FinanceModule::class)->sections($user), 'owners' => Access::owners($user, $cap), 'canCreate' => $user->can($prefix.'create'), 'canAssign' => $user->can($prefix.'assign'), 'canExport' => $user->can($prefix.'export'), 'canRecordPayment' => $user->can($prefix.'record-payment'), 'canCancel' => $user->can($prefix.'cancel'), 'canViewAll' => $user->can($prefix.'view.all'), 'canClassify' => $user->can('identities.classifications.manage'), 'currency' => Installation::settings()['currency'], 'today' => now(Installation::settings()['timezone'])->toDateString(), 'statuses' => self::statuses()];
    }

    public function relationships($user, string $cap): array
    {
        $service = app(IdentityRelationships::class);

        return $service->query($user, Ledger::role($cap))->where('active', true)->orderBy('id')->get()->map(fn ($r) => $service->describe($user, $r))->all();
    }

    public function filters(Request $request, string $cap): array
    {
        $data = $request->validate(['owner_id' => 'nullable|integer|min:1', 'relationship_id' => 'nullable|integer|min:1', 'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'view' => ['nullable', Rule::in(['overdue', 'outstanding', 'paid', 'cancelled', 'unassigned'])]]);
        if ($request->filled('relationship_id')) {
            abort_unless(app(Ledger::class)->relationship($request->user(), $cap, $request->integer('relationship_id')) !== null, 403);
        }if ($request->input('view') === 'unassigned') {
            abort_unless($request->user()->can('finance.'.$cap.'.view.all'), 403);
        }

        return $data;
    }
}
