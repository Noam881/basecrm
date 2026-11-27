<?php

namespace Awove\Core\Http;

use Awove\Core\Access;
use Awove\Core\Models\Document;
use Awove\Core\Services\Documents;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DocumentController
{
    public function index(Request $request, string $type, int $id, Documents $documents)
    {
        return response()->json($documents->forIdentity($request->user(), $type, $id))
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function store(Request $request, string $type, int $id, Documents $documents)
    {
        $returnTo = '/people/'.$type.'/'.$id;
        try {
            $request->validate(['from_lead' => 'nullable|integer|min:1']);
            if ($request->filled('from_lead')) {
                abort_unless(Access::leads($request->user())->whereKey($request->integer('from_lead'))->exists(), 403);
                $returnTo .= '?from_lead='.$request->integer('from_lead');
            }
            $documents->upload($request->user(), $type, $id, $request->allFiles());
        } catch (ValidationException $error) {
            // A metadata GET must never become the fallback Inertia form error destination.
            throw $error->errorBag('identity_documents')->redirectTo($returnTo);
        }

        return redirect($returnTo);
    }

    public function download(Request $request, string $type, int $id, Document $document, Documents $documents)
    {
        return $documents->download($request->user(), $type, $id, $document);
    }
}
