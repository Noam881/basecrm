<?php

namespace Awove\Core\Http;

use Awove\Core\Imports\LegacyHistory;
use Awove\Core\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegacyHistoryController
{
    public function index(Request $request, Lead $lead, LegacyHistory $history): JsonResponse
    {
        $input = $request->validate(['before' => 'sometimes|nullable|integer|min:1']);

        return $this->response($history->page($request->user(), $lead, isset($input['before']) ? (int) $input['before'] : null));
    }

    public function show(Request $request, Lead $lead, int $activity, LegacyHistory $history): JsonResponse
    {
        return $this->response($history->entry($request->user(), $lead, $activity));
    }

    private function response(array $data): JsonResponse
    {
        return response()->json($data)->withHeaders(['Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff']);
    }
}
