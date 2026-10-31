<?php

namespace Tests\Fixtures;

use Awove\Core\Contracts\CapabilityProvider;
use Awove\Core\Contracts\WorkReferenceContributor;
use Awove\Core\Contracts\WorkScopeContributor;
use Awove\Core\Models\Routine;
use Awove\Core\Models\WorkItem;
use Awove\Core\Work\Definition;
use Awove\Core\Work\RoutineType;
use Awove\Core\Work\WorkType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ScopedWorkModule extends WorkModule implements CapabilityProvider, WorkReferenceContributor, WorkScopeContributor
{
    public function __construct()
    {
        parent::__construct('fixture-scoped');
    }

    public function capabilities(): array
    {
        return ['programs', 'projects'];
    }

    public function workTypes(): array
    {
        return [new WorkType($this->id(), 'tasks', 'Scoped work', $this->id(), ['new' => 'New', 'done' => 'Done'], 'new', ['done'], ['done'], '/fixture/scoped', capability: 'projects')];
    }

    public function routineTypes(): array
    {
        return [new RoutineType($this->id(), 'routines', 'Scoped routine', $this->id(), '/fixture/scoped-routines', capability: 'projects')];
    }

    public function scopeWork($user, Definition $type, Builder $query, bool $activeOnly = false): void
    {
        $parents = DB::table('fixture_work_parents')->where('viewer_id', $user->id);
        if ($activeOnly) {
            $parents->where('active', true);
        }
        $query->whereIn($query->getModel()->qualifyColumn('id'), $parents->select($type instanceof WorkType ? 'work_item_id' : 'routine_id'));
    }

    public function workReferences($user, WorkItem|Routine $record): array
    {
        return [['id' => 'fixture-scoped.instructions.1', 'label' => 'Fictional instruction', 'href' => '/fixture/instructions/1']];
    }
}
