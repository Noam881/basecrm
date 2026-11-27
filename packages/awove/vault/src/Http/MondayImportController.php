<?php

namespace Awove\Vault\Http;

use Awove\Vault\AuditLog;
use Awove\Vault\Imports\MondayFiles;
use Awove\Vault\Imports\MondayImport;
use Awove\Vault\Imports\MondayPlan;
use Illuminate\Http\Request;

class MondayImportController
{
    public function status(Request $request, MondayImport $import)
    {
        $plan = MondayPlan::load();

        return response()->json([...$import->status($request), ...app(MondayFiles::class)->progress($request, $plan)]);
    }

    public function next(Request $request, MondayImport $import)
    {
        try {
            $result = $import->next($request);

            return response()->json([...$result, ...app(MondayFiles::class)->progress($request, MondayPlan::load())]);
        } catch (\Throwable $error) {
            AuditLog::record($request->user(), 'import.request', null, 'denied');
            throw $error;
        }
    }

    public function export(Request $request, string $id, MondayImport $import)
    {
        return $import->export($request, $id);
    }

    public function nextFile(Request $request, MondayFiles $files)
    {
        try {
            return response()->json($files->next($request));
        } catch (\Throwable $error) {
            AuditLog::record($request->user(), 'import.file_request', null, 'denied');
            throw $error;
        }
    }

    public function reconcile(Request $request, string $id, MondayFiles $files)
    {
        return response()->json($files->reconcile($request, $id));
    }
}
