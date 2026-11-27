<?php

namespace Awove\Core\Contracts;

/** Registered, module-owned edit authorization for an exact reminder resource. */
interface ImportHoldSource extends ReminderSource
{
    /** Called inside the hold transaction; lock and authorize the current owning resource. */
    public function authorizeImportHold($actor, int $recordId): void;
}
