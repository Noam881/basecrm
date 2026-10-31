<?php

namespace Awove\Core;

use Awove\Core\Models\Status;

/** UI labels are projected from defaults; saved names and filter values stay intact. */
class StatusDisplay
{
    private const DEFAULTS = ['new' => 'New', 'discussion' => 'In discussion', 'proposal' => 'Proposal sent', 'won' => 'Won', 'lost' => 'Lost', 'contacted' => 'Contacted', 'qualified' => 'Qualified'];

    public static function label(Status $status): string
    {
        $name = $status->getRawOriginal('name');

        return (self::DEFAULTS[$status->id] ?? null) === $name ? (string) __($name) : $name;
    }

    public static function copy(Status $status): Status
    {
        $copy = clone $status;
        $copy->setAttribute('name', self::label($status));

        return $copy;
    }
}
