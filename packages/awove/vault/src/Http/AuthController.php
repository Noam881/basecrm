<?php

namespace Awove\Vault\Http;

use App\Models\User;
use Awove\Vault\Enrollment;
use Awove\Vault\Unlocks;
use Illuminate\Http\Request;

class AuthController
{
    public function status(Request $request, Unlocks $unlocks)
    {
        return response()->json($unlocks->status($request));
    }

    public function enroll(Request $request, Enrollment $enrollment)
    {
        return response()->json($enrollment->begin($request, $request->all()));
    }

    public function confirmEnrollment(Request $request, Enrollment $enrollment)
    {
        return response()->json($enrollment->confirm($request, $request->all()));
    }

    public function password(Request $request, Unlocks $unlocks)
    {
        return response()->json($unlocks->password($request, $request->all()));
    }

    public function resend(Request $request, Unlocks $unlocks)
    {
        return response()->json($unlocks->resend($request, $request->all()));
    }

    public function email(Request $request, Unlocks $unlocks)
    {
        return response()->json($unlocks->email($request, $request->all()));
    }

    public function factor(Request $request, Unlocks $unlocks)
    {
        return response()->json($unlocks->factor($request, $request->all()));
    }

    public function lock(Request $request, Unlocks $unlocks)
    {
        return response()->json($unlocks->lock($request));
    }

    public function approve(Request $request, Unlocks $unlocks, Enrollment $enrollment)
    {
        $unlocks->requireUnlocked($request);
        $actor = $unlocks->actor($request);
        abort_unless($actor->can('vault.admin'), 403);
        $data = $request->validate(['user_id' => 'required|integer|min:1']);
        $target = User::findOrFail($data['user_id']);

        return response()->json($enrollment->approve($actor, $target, false, $request));
    }

    public function reset(Request $request, Unlocks $unlocks, Enrollment $enrollment)
    {
        $unlocks->requireUnlocked($request);
        $actor = $unlocks->actor($request);
        abort_unless($actor->can('vault.admin'), 403);
        $data = $request->validate(['user_id' => 'required|integer|min:1']);
        $target = User::findOrFail($data['user_id']);

        return response()->json($enrollment->approve($actor, $target, true, $request));
    }
}
