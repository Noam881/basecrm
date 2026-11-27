<?php

namespace Awove\Vault\Http;

use Awove\Vault\AuditLog;
use Awove\Vault\Imports\MondayWorkdocs;
use Illuminate\Http\Request;

class MondayWorkdocController
{
    public function status(Request $request, MondayWorkdocs $import)
    {
        return response()->json($import->status($request));
    }

    public function next(Request $request, MondayWorkdocs $import)
    {
        try {
            return response()->json($import->next($request));
        } catch (\Throwable $error) {
            AuditLog::record($request->user(), 'import.workdoc_request', null, 'denied');
            throw $error;
        }
    }

    public function export(Request $request, string $id, MondayWorkdocs $import)
    {
        return $import->export($request, $id);
    }
}
