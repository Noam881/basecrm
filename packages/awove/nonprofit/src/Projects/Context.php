<?php

namespace Awove\Nonprofit\Projects;

use Awove\Core\Currency;
use Awove\Core\Installation;
use Awove\Core\Services\WorkItems;
use Awove\Nonprofit\NonprofitModule;
use Awove\Nonprofit\Projects\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class Context
{
    public function options($user, ?Project $p = null): array
    {
        $u = Access::actor($user);
        $types = (new Capability)->workTypes();

        return ['sections' => (new NonprofitModule)->sections($u), 'statuses' => Records::statuses(), 'workStatuses' => $types[0]->statuses, 'currentOwnerId' => $u->id, 'owners' => Access::owners($u), 'workOwners' => $p ? Access::owners($u, $p) : Access::owners($u), 'today' => now(Installation::settings()['timezone'])->toDateString(), 'currency' => Currency::current(), 'currencyError' => app(Currency::class)->consistent() ? null : Currency::ERROR, 'canCreate' => $u->can('nonprofit.projects.create'), 'canAssign' => $u->can('nonprofit.projects.assign'), 'canArchive' => $u->can('nonprofit.projects.archive'), 'canExport' => $u->can('nonprofit.projects.export'), 'canViewBudget' => $u->can('nonprofit.projects.budgets.view'), 'canManageBudget' => $u->can('nonprofit.projects.budgets.view') && $u->can('nonprofit.projects.budgets.manage'), 'canViewDocuments' => $u->can('nonprofit.projects.documents.view'), 'canManageDocuments' => $u->can('nonprofit.projects.documents.view') && $u->can('nonprofit.projects.documents.manage')];
    }

    public function filters(Request $r): array
    {
        return $r->validate(['section' => ['nullable', Rule::in(['projects', 'project-tasks', 'project-milestones'])], 'owner_id' => 'nullable|integer|min:1', 'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($r->filled('from') ? ['after_or_equal:from'] : [])], 'view' => ['nullable', Rule::in(['active', 'archived', 'overdue'])]]);
    }

    public function query(Request $r)
    {
        $f = $this->filters($r);
        $section = $f['section'] ?? 'projects';
        $q = $section === 'projects' ? Access::query($r->user()) : app(WorkItems::class)->query($r->user(), 'nonprofit', $section);
        $t = $q->getModel()->getTable();
        if ($r->input('view') === 'archived') {
            $q->whereNotNull("$t.archived_at");
        } else {
            $q->whereNull("$t.archived_at");
        }
        if ($r->filled('owner_id')) {
            $id = $r->integer('owner_id');
            $visible = (clone $q)->where("$t.owner_id", $id)->exists();
            abort_unless($visible || $id === $r->user()->id, 403);
            $q->where("$t.owner_id", $id);
        }
        $date = $section === 'projects' ? 'starts_on' : 'due_on';
        if ($r->filled('from')) {
            $q->where("$t.$date", '>=', $f['from']);
        }if ($r->filled('to')) {
            $q->where("$t.$date", '<=', $f['to']);
        }
        if ($r->input('view') === 'overdue') {
            $q->whereNotIn("$t.status", $section === 'projects' ? ['completed', 'cancelled'] : ['done', 'cancelled'])->where("$t.".($section === 'projects' ? 'ends_on' : 'due_on'), '<', now(Installation::settings()['timezone'])->toDateString());
        }

        return $q;
    }
}
