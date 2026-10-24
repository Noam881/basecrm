<?php

namespace Awove\Core\Contracts;

interface ReminderContributor
{
    /** @return list<ReminderSource> */
    public function reminderSources(): array;
}
