<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFolderRequest;
use App\Http\Requests\UpdateFolderRequest;
use App\Http\Resources\FolderResource;
use App\Services\FolderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    use ResolvesWorkspace;

    public function __construct(private FolderService $folderService) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('trash')) {
            $folders = $this->folderService->listTrash(
                $this->workspace($request),
                [
                    'q' => $request->input('q'),
                    'sort' => $request->input('sort', 'updated_desc'),
                ]
            );

            return response()->json([
                'folders' => FolderResource::collection($folders),
            ]);
        }

        $parentId = $request->filled('parent_id')
            ? $request->integer('parent_id')
            : null;

        $folders = $this->folderService->list(
            $this->workspace($request),
            $parentId
        );

        return response()->json([
            'folders' => FolderResource::collection($folders),
        ]);
    }

    public function store(StoreFolderRequest $request): JsonResponse
    {
        $folder = $this->folderService->create(
            $this->workspace($request),
            $request->validated()
        );

        return response()->json([
            'folder' => new FolderResource($folder),
        ], 201);
    }

    public function update(UpdateFolderRequest $request, int $id): JsonResponse
    {
        $folder = $this->folderService->update(
            $this->workspace($request),
            $id,
            $request->validated()
        );

        return response()->json([
            'folder' => new FolderResource($folder),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->folderService->softDeleteCascade(
            $this->workspace($request),
            $id
        );

        return response()->json(['message' => 'Folder moved to trash.']);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $folder = $this->folderService->restoreCascade(
            $this->workspace($request),
            $id
        );

        return response()->json([
            'message' => 'Folder restored.',
            'folder' => new FolderResource($folder),
        ]);
    }

    public function forceDestroy(Request $request, int $id): JsonResponse
    {
        $this->folderService->forceDeleteCascade(
            $this->workspace($request),
            $id
        );

        return response()->json([
            'message' => 'Folder permanently deleted.',
        ]);
    }
}
