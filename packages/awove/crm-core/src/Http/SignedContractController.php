<?php

namespace Awove\Core\Http;

use Awove\Core\Models\Lead;
use Awove\Core\Models\SignedContract;
use Awove\Core\Services\SignedContracts;
use Illuminate\Http\Request;

class SignedContractController
{
    public function index(Request $request, Lead $lead, SignedContracts $contracts)
    {
        return response()->json(['signed_contracts' => $contracts->forLead($request->user(), $lead)]);
    }

    public function store(Request $request, Lead $lead, SignedContracts $contracts)
    {
        $contracts->upload($request->user(), $lead, $request->allFiles());

        return redirect('/leads/'.$lead->id);
    }

    public function download(Request $request, Lead $lead, SignedContract $signedContract, SignedContracts $contracts)
    {
        return $contracts->download($request->user(), $lead, $signedContract);
    }
}
