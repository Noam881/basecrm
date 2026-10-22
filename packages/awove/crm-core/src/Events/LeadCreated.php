<?php

namespace Awove\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class LeadCreated implements ShouldDispatchAfterCommit
{
    public function __construct(public int $leadId, public int $actorId) {}
}
