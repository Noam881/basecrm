<?php

namespace Awove\Core\Contracts;

use Awove\Core\Work\RoutineType;
use Awove\Core\Work\WorkType;

interface WorkContributor
{
    /** @return list<WorkType> */
    public function workTypes(): array;

    /** @return list<RoutineType> */
    public function routineTypes(): array;
}
