<?php

namespace Awove\Core\Http;

use Awove\Core\Models\Lead;
use Awove\Core\Models\Quotation;
use Awove\Core\Services\Sales;
use Illuminate\Http\Request;

class SalesController
{
    public function communicate(Request $request, Lead $lead, Sales $sales)
    {
        $sales->communicate($request->user(), $lead, $request->all());

        return back();
    }

    public function quotation(Request $request, Lead $lead, Sales $sales)
    {
        $sales->quotation($request->user(), $lead, $request->all());

        return back();
    }

    public function updateQuotation(Request $request, Lead $lead, Quotation $quotation, Sales $sales)
    {
        abort_unless($quotation->lead_id === $lead->id, 404);
        $sales->quotation($request->user(), $lead, $request->all(), $quotation);

        return back();
    }
}
