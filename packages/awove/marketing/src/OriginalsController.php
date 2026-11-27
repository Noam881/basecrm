<?php

namespace Awove\Marketing;

use Awove\Marketing\Imports\Originals;
use Illuminate\Http\Request;

class OriginalsController
{
    public function index(Request $request, string $type, int $id, Originals $originals)
    {
        $data = $request->validate(['before' => 'nullable|integer|min:1']);

        return response()->json($originals->page($request->user(), $type === 'leads' ? 'source-lead' : 'source-company', $id, isset($data['before']) ? (int) $data['before'] : null))
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $type, int $id, int $entry, Originals $originals)
    {
        return response()->json($originals->entry($request->user(), $type === 'leads' ? 'source-lead' : 'source-company', $id, $entry))
            ->header('Cache-Control', 'private, no-store');
    }
}
