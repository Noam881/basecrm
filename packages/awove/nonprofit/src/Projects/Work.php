<?php

namespace Awove\Nonprofit\Projects;

use Awove\Core\Models\WorkItem;
use Awove\Core\Services\WorkItems;
use Awove\Nonprofit\Projects\Models\Project;
use Awove\Nonprofit\Projects\Models\ProjectWork;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Work
{
    public static function kind(string $kind): void
    {
        abort_unless(in_array($kind, ['project-tasks', 'project-milestones'], true), 404);
    }

    public function parent($u, string $kind, int $id): Project
    {
        self::kind($kind);
        app(WorkItems::class)->find($u, 'nonprofit', $kind, $id);
        $link = ProjectWork::where('kind', $kind)->where('work_item_id', $id)->firstOrFail();

        return Access::find($u, $link->project_id);
    }

    public function createWithin($u, Project $p, string $kind, array $input): WorkItem
    {
        return DB::transaction(function () use ($u, $p, $kind, $input) {
            $u = Access::actor($u);
            $current = Access::query($u)->lockForUpdate()->findOrFail($p->id);
            Access::edit($u, $current, true);
            abort_unless($current->version === $p->version, 409, __('The project changed. Reload before adding work.'));
            self::kind($kind);
            $this->owner($u, $current, $input, null);
            $w = app(WorkItems::class)->create($u, 'nonprofit', $kind, array_intersect_key($input, array_flip(WorkItems::FIELDS)), ['project_id']);
            ProjectWork::create(['project_id' => $current->id, 'work_item_id' => $w->id, 'kind' => $kind]);

            return $w;
        });
    }

    public function save($u, int $id, string $kind, array $input, ?int $workId = null): WorkItem
    {
        return app(Records::class)->mutate($u, $id, $input, function ($u, $p) use ($kind, $input, $workId) {
            self::kind($kind);
            if ($workId) {
                ProjectWork::where('project_id', $p->id)->where('kind', $kind)->where('work_item_id', $workId)->firstOrFail();
                $old = app(WorkItems::class)->find($u, 'nonprofit', $kind, $workId);
                $this->owner($u, $p, $input, $old);
                $w = app(WorkItems::class)->update($u, 'nonprofit', $kind, $workId, array_intersect_key($input, array_flip([...WorkItems::FIELDS, 'version'])));
            } else {
                $w = $this->createWithin($u, $p, $kind, $input);
            }
            app(Records::class)->change($u, $p, $workId ? 'work.updated' : 'work.created', [$kind]);

            return $w;
        });
    }

    private function owner($u, Project $p, array $input, ?WorkItem $old): void
    {
        $data = validator($input, ['owner_id' => 'nullable|integer|min:1'])->validate();
        $id = array_key_exists('owner_id', $input) ? (isset($data['owner_id']) ? (int) $data['owner_id'] : null) : ($old ? $old->owner_id : $u->id);
        if ($old && $old->owner_id === $id) {
            return;
        }if ($id !== null) {
            $owner = config('auth.providers.users.model')::find($id);
            if (! $owner || ! Access::visible($owner, $p)) {
                throw ValidationException::withMessages(['owner_id' => __('Choose an active owner who can already view this project.')]);
            }
        }
    }

    public function archive($u, int $id, string $kind, int $workId, array $input): void
    {
        app(Records::class)->mutate($u, $id, $input, function ($u, $p) use ($kind, $workId, $input) {
            self::kind($kind);
            ProjectWork::where('project_id', $p->id)->where('kind', $kind)->where('work_item_id', $workId)->firstOrFail();
            app(WorkItems::class)->archive($u, 'nonprofit', $kind, $workId, $input);
            app(Records::class)->change($u, $p, 'work.archived', [$kind]);
        });
    }

    public function describe($u, WorkItem $w): array
    {
        $w = app(WorkItems::class)->find($u, 'nonprofit', $w->kind, $w->id);
        $p = $this->parent($u, $w->kind, $w->id);

        return [...$w->toArray(), 'project' => ['id' => $p->id, 'name' => $p->name, 'version' => $p->version], 'owner' => $w->owner?->only(['id', 'name']), 'editable' => app(WorkItems::class)->editable($u, $w), 'record_url' => '/nonprofit/'.$w->kind.'/'.$w->id, 'record_link_column' => 'title'];
    }
}
