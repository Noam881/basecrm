<?php

namespace Awove\Nonprofit\Projects;

use Awove\Core\Services\WorkItems;
use Awove\Core\Services\WorkReferences;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WorkController
{
    public function store(Request $r, int $id, string $kind)
    {
        $w = app(Work::class)->save($r->user(), $id, $kind, $r->all());

        return redirect(app(WorkItems::class)->query($r->user(), 'nonprofit', $kind)->whereKey($w->id)->exists() ? '/nonprofit/'.$kind.'/'.$w->id : '/nonprofit/projects/'.$id);
    }

    public function show(Request $r, int $id, string $kind, Context $context)
    {
        $w = app(WorkItems::class)->find($r->user(), 'nonprofit', $kind, $id);
        $p = app(Work::class)->parent($r->user(), $kind, $id);

        return Inertia::render('Nonprofit/Projects/Work', [...$context->options($r->user(), $p), 'record' => app(Work::class)->describe($r->user(), $w), 'history' => app(WorkItems::class)->history($r->user(), $w), 'instructionLinks' => app(WorkReferences::class)->for($r->user(), $w)]);
    }

    public function update(Request $r, int $id, string $kind)
    {
        $p = app(Work::class)->parent($r->user(), $kind, $id);
        app(Work::class)->save($r->user(), $p->id, $kind, $r->all(), $id);

        return back();
    }

    public function archive(Request $r, int $id, string $kind)
    {
        $p = app(Work::class)->parent($r->user(), $kind, $id);
        app(Work::class)->archive($r->user(), $p->id, $kind, $id, $r->all());

        return redirect('/nonprofit/projects/'.$p->id);
    }
}
