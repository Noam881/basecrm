<?php

namespace Tests\Fixtures;

use Awove\Core\Contracts\Module;
use Awove\Core\Contracts\WorkContributor;
use Awove\Core\Work\RoutineType;
use Awove\Core\Work\WorkType;

class WorkModule implements Module, WorkContributor
{
    public function __construct(private string $moduleId = 'fixture-work') {}

    public function id(): string
    {
        return $this->moduleId;
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
        return $this->workTypes()[0]->permissions();
    }

    public function navigation(): array
    {
        return [];
    }

    public function panels(): array
    {
        return [];
    }

    public function workTypes(): array
    {
        return [new WorkType($this->id(), 'tasks', 'Fictional work', $this->id(), ['new' => 'New', 'in-progress' => 'In progress', 'done' => 'Done', 'cancelled' => 'Cancelled'], 'new', ['done', 'cancelled'], ['done'], '/fixture/tasks')];
    }

    public function routineTypes(): array
    {
        return [new RoutineType($this->id(), 'routines', 'Fictional routine', $this->id(), '/fixture/routines')];
    }
}
