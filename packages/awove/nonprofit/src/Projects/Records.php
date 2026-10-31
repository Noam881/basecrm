<?php

namespace Awove\Nonprofit\Projects;

use Awove\Core\Currency;
use Awove\Core\Installation;
use Awove\Core\Money;
use Awove\Core\Services\DocumentLinks;
use Awove\Nonprofit\Projects\Models\Budget;
use Awove\Nonprofit\Projects\Models\Document;
use Awove\Nonprofit\Projects\Models\History;
use Awove\Nonprofit\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Records
{
    public static function statuses(): array
    {
        return array_replace(['planned' => 'Planned', 'in-progress' => 'In progress', 'on-hold' => 'On hold', 'completed' => 'Completed', 'cancelled' => 'Cancelled'], array_intersect_key(Installation::manifest()['nonprofit_project_statuses'] ?? [], array_flip(['planned', 'in-progress', 'on-hold', 'completed', 'cancelled'])));
    }

    public function save($user, array $input, ?int $id = null): Project
    {
        return DB::transaction(function () use ($user, $input, $id) {
            $u = Access::actor($user);
            $p = $id ? Access::query($u)->lockForUpdate()->findOrFail($id) : null;
            if ($p) {
                Access::edit($u, $p);
                Access::version($p, $input);
            } else {
                abort_unless($u->can('nonprofit.projects.create'), 403);
            }
            $defaults = $p ? [...$p->only(['name', 'objective', 'owner_id', 'status']), 'starts_on' => $p->getRawOriginal('starts_on'), 'ends_on' => $p->getRawOriginal('ends_on')] : ['owner_id' => $u->id, 'status' => 'planned'];
            $data = validator([...$defaults, ...$input], [
                'name' => 'required|string|max:180', 'objective' => 'required|string|max:20000', 'owner_id' => 'nullable|integer|min:1', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'status' => ['required', Rule::in(array_keys(self::statuses()))],
            ])->validate();
            foreach (['name', 'objective'] as $f) {
                $data[$f] = trim($data[$f]);
                if ($data[$f] === '') {
                    throw ValidationException::withMessages([$f => 'Enter a value.']);
                }
            }
            $data['owner_id'] = isset($data['owner_id']) ? (int) $data['owner_id'] : null;
            Access::owner($u, $data['owner_id'], $p);
            if ($p) {
                $p->fill($data);
                $fields = array_keys($p->getDirty());
            } else {
                $p = Project::create([...$data, 'created_by' => $u->id]);
                $fields = array_keys($data);
            }
            if ($this->budget($u, $p, $input)) {
                $fields[] = 'planned_budget';
            }
            if ($id) {
                $p->version++;
                $p->save();
            } $this->historyEntry($u, $p, $id ? 'project.updated' : 'project.created', $fields);

            return $p;
        });
    }

    private function budget($u, Project $p, array $input): bool
    {
        if (! array_key_exists('planned_budget', $input)) {
            return false;
        }
        $old = Budget::where('project_id', $p->id)->latest('id')->first();
        if (! filled($input['planned_budget'])) {
            if ($old && $u->can('nonprofit.projects.budgets.view')) {
                throw ValidationException::withMessages(['planned_budget' => 'Enter a revised amount, including zero, to retain budget history.']);
            }

            return false;
        }
        abort_unless($u->can('nonprofit.projects.budgets.view') && $u->can('nonprofit.projects.budgets.manage'), 403);
        $amount = Money::amount($input['planned_budget'], 'planned_budget');
        if ($old && $old->amount === $amount) {
            return false;
        }
        app(Currency::class)->assertConsistent();
        Budget::create(['project_id' => $p->id, 'amount' => $amount, 'currency' => Currency::current(), 'actor_id' => $u->id]);

        return true;
    }

    public function mutate($user, int $id, array $input, \Closure $callback, bool $activeOnly = true): mixed
    {
        return DB::transaction(function () use ($user, $id, $input, $callback, $activeOnly) {
            $u = Access::actor($user);
            $p = Access::query($u)->lockForUpdate()->findOrFail($id);
            Access::edit($u, $p, $activeOnly);
            Access::version($p, $input, 'project_version');

            return $callback($u, $p);
        });
    }

    public function change($u, Project $p, string $event, array $fields): void
    {
        $p->version++;
        $p->save();
        $this->historyEntry($u, $p, $event, $fields);
    }

    public function historyEntry($u, Project $p, string $event, array $fields): void
    {
        History::create(['project_id' => $p->id, 'actor_id' => $u->id, 'event' => $event, 'changed_fields' => array_values(array_unique($fields))]);
    }

    public function archive($u, int $id, array $input): void
    {
        DB::transaction(function () use ($u, $id, $input) {
            $u = Access::actor($u);
            $p = Access::query($u)->lockForUpdate()->findOrFail($id);
            Access::edit($u, $p);
            abort_unless($u->can('nonprofit.projects.archive'), 403);
            Access::version($p, $input);
            $p->archived_at = now();
            $this->change($u, $p, 'project.archived', ['archived_at']);
        });
    }

    public function describe($u, Project $p): array
    {
        $p = Access::find($u, $p->id);
        $visible = $u->fresh()->can('nonprofit.projects.budgets.view');
        $budget = $visible ? Budget::where('project_id', $p->id)->latest('id')->first() : null;

        return [...$p->only(['id', 'name', 'objective', 'owner_id', 'status', 'version', 'archived_at']), 'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on->toDateString(), 'owner' => config('auth.providers.users.model')::find($p->owner_id)?->only(['id', 'name']), 'planned_budget' => $budget?->amount, 'currency' => $budget?->currency, 'budget_available' => $visible, 'editable' => Access::editable($u, $p), 'active' => ! $p->archived_at && ! in_array($p->status, ['completed', 'cancelled'], true)];
    }

    public function history($u, Project $p): array
    {
        Access::find($u, $p->id);

        return History::where('project_id', $p->id)->latest('id')->get()->map(fn ($h) => ['id' => $h->id, 'event' => $h->event, 'changed_fields' => $h->changed_fields, 'actor' => config('auth.providers.users.model')::find($h->actor_id)?->only(['id', 'name']), 'created_at' => $h->created_at->toISOString()])->all();
    }

    public function budgets($u, Project $p): array
    {
        Access::find($u, $p->id);
        if (! $u->fresh()->can('nonprofit.projects.budgets.view')) {
            return [];
        }

        return Budget::where('project_id', $p->id)->latest('id')->get()->map(fn ($b) => ['id' => $b->id, 'amount' => $b->amount, 'currency' => $b->currency, 'actor' => config('auth.providers.users.model')::find($b->actor_id)?->only(['id', 'name']), 'created_at' => $b->created_at->toISOString()])->all();
    }

    public function document($u, int $id, array $input): Document
    {
        return $this->mutate($u, $id, $input, function ($u, $p) use ($input) {
            abort_unless($u->can('nonprofit.projects.documents.view') && $u->can('nonprofit.projects.documents.manage'), 403);
            $data = validator($input, ['name' => 'required|string|max:180', 'url' => 'required|string|max:2048'])->validate();
            $data['name'] = trim($data['name']);
            if ($data['name'] === '') {
                throw ValidationException::withMessages(['name' => 'Enter a document name.']);
            }$data['url'] = app(DocumentLinks::class)->validate($data['url']);
            $doc = Document::create([...$data, 'project_id' => $p->id, 'actor_id' => $u->id]);
            $this->change($u, $p, 'document.added', ['documents']);

            return $doc;
        });
    }

    public function documents($u, Project $p): array
    {
        Access::find($u, $p->id);
        if (! $u->fresh()->can('nonprofit.projects.documents.view')) {
            return [];
        }

        return Document::where('project_id', $p->id)->get()->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'version' => $d->version, 'archived_at' => $d->archived_at, 'href' => '/nonprofit/project-documents/'.$d->id])->all();
    }

    public function archiveDocument($u, int $id, array $input): void
    {
        $doc = Document::findOrFail($id);
        $this->mutate($u, $doc->project_id, $input, function ($u, $p) use ($id, $input) {
            abort_unless($u->can('nonprofit.projects.documents.view') && $u->can('nonprofit.projects.documents.manage'), 403);
            $doc = Document::where('project_id', $p->id)->lockForUpdate()->findOrFail($id);
            Access::version($doc, $input);
            abort_if($doc->archived_at !== null, 409);
            $doc->update(['archived_at' => now(), 'version' => $doc->version + 1]);
            $this->change($u, $p, 'document.archived', ['documents']);
        });
    }
}
