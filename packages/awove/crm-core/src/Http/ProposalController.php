<?php

namespace Awove\Core\Http;

use Awove\Core\Models\Lead;
use Awove\Core\Models\Proposal;
use Awove\Core\Services\Proposals;
use Illuminate\Http\Request;

class ProposalController
{
    public function index(Request $request, Lead $lead, Proposals $proposals)
    {
        return response()->json(['proposals' => $proposals->forLead($request->user(), $lead)]);
    }

    public function store(Request $request, Lead $lead, Proposals $proposals)
    {
        $proposals->upload($request->user(), $lead, $request->allFiles());

        return redirect('/leads/'.$lead->id);
    }

    public function download(Request $request, Lead $lead, Proposal $proposal, Proposals $proposals)
    {
        return $proposals->download($request->user(), $lead, $proposal);
    }
}
