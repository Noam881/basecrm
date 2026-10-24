<?php

namespace Awove\Core\Contracts;

/** Optional module overview contributions, calculated through the module's access scope. */
interface DashboardContributor
{
    /**
     * Do not return widgets when the user cannot access their underlying records.
     * Counts must reflect scoped persisted records; decimal amounts remain strings.
     * IDs and permission names begin with the declaring module's ID followed by a dot.
     *
     * @return list<array{id:string,label:string,value:int|string,href:string,permission:string,anyPermissions?:list<string>}>
     */
    public function widgets($user): array;
}
