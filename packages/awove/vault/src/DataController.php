<?php

namespace Awove\Vault;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class DataController
{
    public function listing(Request $request, Records $records)
    {
        return response()->json($records->listing($request));
    }

    public function options(Request $request, Records $records)
    {
        return response()->json($records->options($request->user()));
    }

    public function show(Request $request, string $id, Records $records)
    {
        return response()->json(['item' => $records->metadata($records->find($id), $request->user())]);
    }

    public function store(Request $request, Records $records)
    {
        return $this->perform($request, 'item.create', null, function () use ($request, $records) {
            $item = $records->create($request);

            return response()->json(['item' => $records->metadata($item, $request->user(), 'create')], 201);
        });
    }

    public function update(Request $request, string $id, Records $records)
    {
        return $this->perform($request, 'item.update', $id, function () use ($request, $id, $records) {
            $item = $records->update($request, $id);

            return response()->json(['item' => $records->metadata($item, $request->user(), 'update')]);
        });
    }

    public function remove(Request $request, string $id, Records $records)
    {
        return $this->perform($request, 'item.delete', $id, function () use ($request, $id, $records) {
            $records->remove($request, $id);

            return response()->json(['deleted' => true]);
        });
    }

    public function content(Request $request, string $id, Records $records)
    {
        return $this->perform($request, 'item.read', $id, fn () => response()->json($records->content($request, $id)));
    }

    public function upload(Request $request, string $id, Files $files, Records $records)
    {
        return $this->perform($request, 'file.upload', $id, function () use ($request, $id, $files, $records) {
            $item = $files->upload($request, $id);

            return response()->json(['item' => $records->metadata($item, $request->user(), 'files.upload'), 'files' => Access::allowed($request->user(), 'read', $item) ? $files->metadata($request, $item) : []]);
        });
    }

    public function download(Request $request, string $id, Files $files)
    {
        return $this->perform($request, 'file.download', null, fn () => $files->download($request, $id));
    }

    public function removeFile(Request $request, string $id, Files $files, Records $records)
    {
        return $this->perform($request, 'file.delete', null, function () use ($request, $id, $files, $records) {
            $item = $files->remove($request, $id);

            return response()->json(['item' => $records->metadata($item, $request->user(), 'files.delete'), 'files' => Access::allowed($request->user(), 'read', $item) ? $files->metadata($request, $item) : []]);
        });
    }

    /** Record failures after rolled-back mutations; never record exception text or input. */
    private function perform(Request $request, string $action, ?string $id, Closure $operation)
    {
        try {
            return $operation();
        } catch (\Throwable $error) {
            $denied = $error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [401, 403, 404, 423], true);
            AuditLog::record($request->user(), $action, $id, $denied ? 'denied' : 'failed');
            throw $error;
        }
    }
}
