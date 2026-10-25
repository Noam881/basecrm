<?php

namespace Awove\Core\Contracts;

use Awove\Core\Models\Routine;
use Awove\Core\Models\WorkItem;

/** Optional instruction providers receive a freshly authorized shared work record. */
interface WorkReferenceContributor
{
    /**
     * Recheck the provider's own record permissions and return only visible instruction links.
     * IDs belong to the provider namespace, for example knowledge.instructions.42.
     *
     * @return list<array{id:string,label:string,href:string}>
     */
    public function workReferences($user, WorkItem|Routine $record): array;
}
