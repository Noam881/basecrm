<?php

namespace Awove\Core\Http;

use Awove\Core\Modules;
use Awove\Core\Services\Spreadsheet;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SpreadsheetController
{
    public function index(Request $request, Spreadsheet $spreadsheets)
    {
        $user = $request->user();
        abort_unless($user->active && ($user->can('imports.manage') || $user->can('exports.view')), 403);
        $types = $spreadsheets->definitions();
        if (! app(Modules::class)->enabled('sales')) {
            unset($types['leads']);
        }

        return Inertia::render('Core/Import', ['definitions' => $types, 'canImport' => $user->can('imports.manage') && $user->can('leads.create'), 'canExport' => $user->can('exports.view')]);
    }

    public function preview(Request $request, Spreadsheet $spreadsheets)
    {
        $spreadsheets->authorize($request->user(), 'import');
        $data = $request->validate(['type' => 'required|string', 'file' => 'required|file|max:1024']);

        return response()->json($spreadsheets->preview($request->user(), $data['type'], $request->file('file')));
    }

    public function confirm(Request $request, Spreadsheet $spreadsheets)
    {
        $spreadsheets->authorize($request->user(), 'import');
        $data = $request->validate(['token' => 'required|string|max:64', 'accept_duplicates' => 'sometimes|boolean']);

        return response()->json($spreadsheets->confirm($request->user(), $data['token'], $data['accept_duplicates'] ?? false));
    }

    public function export(Request $request, string $type, Spreadsheet $spreadsheets)
    {
        return $spreadsheets->export($request->user(), $type);
    }

    public function template(Request $request, string $type, Spreadsheet $spreadsheets)
    {
        return $spreadsheets->template($request->user(), $type);
    }
}
