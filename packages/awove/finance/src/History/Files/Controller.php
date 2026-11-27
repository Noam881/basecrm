<?php

namespace Awove\Finance\History\Files;

use Illuminate\Http\Request;

class Controller
{
    public function index(Request $request, int $id, Files $files)
    {
        return response()->json(['files' => $files->forRecord($request->user(), $id)])->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $request, int $id, string $attachment, Files $files)
    {
        return $files->download($request->user(), $id, $attachment);
    }
}
