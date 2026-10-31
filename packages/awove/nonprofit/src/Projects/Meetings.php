<?php

namespace Awove\Nonprofit\Projects;

use Awove\Core\Services\WorkItems;
use Awove\Nonprofit\Projects\Models\Meeting;
use Awove\Nonprofit\Projects\Models\Project;
use Illuminate\Validation\ValidationException;

class Meetings
{
    public function save($u, int $id, array $input, ?int $meetingId = null): Meeting
    {
        return app(Records::class)->mutate($u, $id, $input, function ($u, $p) use ($input, $meetingId) {
            $m = $meetingId ? Meeting::where('project_id', $p->id)->lockForUpdate()->findOrFail($meetingId) : null;
            if ($m) {
                Access::version($m, $input);
            } else {
                abort_unless($u->can('nonprofit.projects.create'), 403);
            }
            $data = validator($input, ['held_on' => 'required|date_format:Y-m-d', 'summary' => 'required|string|max:20000', 'decisions' => 'nullable|string|max:20000', 'follow_up' => 'nullable|array'])->validate();
            foreach (['summary', 'decisions'] as $f) {
                $data[$f] = filled($data[$f] ?? null) ? trim($data[$f]) : null;
            }if (! $data['summary']) {
                throw ValidationException::withMessages(['summary' => 'Enter a meeting summary.']);
            }
            $follow = null;
            if (! empty($data['follow_up'])) {
                if ($m?->follow_up_work_item_id) {
                    throw ValidationException::withMessages(['follow_up' => 'Edit the existing follow-up from its task profile.']);
                }$follow = app(Work::class)->createWithin($u, $p, 'project-tasks', $data['follow_up']);
            }unset($data['follow_up']);
            if ($m) {
                $m->fill($data);
                if ($follow) {
                    $m->follow_up_work_item_id = $follow->id;
                }$m->version++;
                $m->save();
            } else {
                $m = Meeting::create([...$data, 'project_id' => $p->id, 'created_by' => $u->id, 'follow_up_work_item_id' => $follow?->id]);
            }
            app(Records::class)->change($u, $p, $meetingId ? 'meeting.updated' : 'meeting.created', ['meetings', ...($follow ? ['project-tasks'] : [])]);

            return $m;
        });
    }

    public function forProject($u, Project $p): array
    {
        Access::find($u, $p->id);

        return Meeting::where('project_id', $p->id)->orderByDesc('held_on')->orderByDesc('id')->get()->map(function ($m) use ($u) {
            $follow = $m->follow_up_work_item_id ? app(WorkItems::class)->query($u, 'nonprofit', 'project-tasks')->find($m->follow_up_work_item_id) : null;

            return ['id' => $m->id, 'held_on' => $m->held_on->toDateString(), 'summary' => $m->summary, 'decisions' => $m->decisions, 'version' => $m->version, 'follow_up' => $follow ? app(Work::class)->describe($u, $follow) : null, 'follow_up_restricted' => (bool) $m->follow_up_work_item_id && ! $follow];
        })->all();
    }
}
