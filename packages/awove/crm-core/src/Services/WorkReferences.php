<?php

namespace Awove\Core\Services;

use Awove\Core\Contracts\WorkReferenceContributor;
use Awove\Core\Models\Routine;
use Awove\Core\Models\WorkItem;
use Awove\Core\Modules;

/** A small read-only bridge; the instruction provider owns relations and their mutations. */
class WorkReferences
{
    /** @return list<array{id:string,label:string,href:string}> */
    public function for($user, WorkItem|Routine $record): array
    {
        $user = $user?->fresh();
        if (! $user || ! $user->active || ! $record->exists) {
            return [];
        }
        $service = $record instanceof Routine ? app(Routines::class) : app(WorkItems::class);
        $parent = $service->query($user, $record->getAttribute('module'), $record->getAttribute('kind'))->find($record->getKey());
        if (! $parent) {
            return [];
        }
        $registry = app(Modules::class);
        $links = [];
        foreach ($registry->installed() as $module => $contributor) {
            if (! $registry->enabled($module) || ! $contributor instanceof WorkReferenceContributor) {
                continue;
            }
            $references = $contributor->workReferences($user, $parent);
            foreach ($references as $reference) {
                if (! preg_match('/^'.preg_quote($module, '/').'\\.[a-z][a-z0-9.-]{0,119}$/D', $reference['id'])
                    || isset($links[$reference['id']])
                    || trim($reference['label']) === '' || mb_strlen($reference['label']) > 180
                    || ! str_starts_with($reference['href'], '/') || str_starts_with($reference['href'], '//')
                    || strlen($reference['href']) > 2048 || preg_match('/[\\x00-\\x20\\\\]/', $reference['href'])) {
                    throw new \RuntimeException('Invalid or duplicate work instruction reference for module '.$module.'.');
                }
                $links[$reference['id']] = array_intersect_key($reference, array_flip(['id', 'label', 'href']));
            }
        }

        return array_values($links);
    }
}
