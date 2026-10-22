<?php

namespace Awove\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class LeadStatusChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public int $leadId, public string $from, public string $to, public int $actorId) {}
}
