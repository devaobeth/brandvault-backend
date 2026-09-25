<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFolderRequest;
use App\Http\Requests\UpdateFolderRequest;
use App\Http\Resources\FolderResource;
use App\Services\ActivityLogService;
use App\Services\FolderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    use ResolvesWorkspace;

    public function __construct(
        private FolderService $folderService,
        private ActivityLogService $activityLogService,
    ) {}

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
        $workspace = $this->workspace($request);
        $user = $request->user();

        $folder = $this->folderService->create($workspace, $request->validated());

        $this->activityLogService->log(
            $workspace,
            $user,
            'folder.created',
            $this->activityLogService->byUser('created', 'Folder “'.$folder->name.'”', $user),
            'folder',
            $folder->id,
        );

        return response()->json([
            'folder' => new FolderResource($folder),
        ], 201);
    }

    public function update(UpdateFolderRequest $request, int $id): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $request->user();
        $data = $request->validated();

        $folder = $this->folderService->update($workspace, $id, $data);

        $verb = array_key_exists('name', $data) ? 'renamed' : 'updated';

        $this->activityLogService->log(
            $workspace,
            $user,
            'folder.updated',
            $this->activityLogService->byUser($verb, 'Folder “'.$folder->name.'”', $user),
            'folder',
            $folder->id,
        );

        return response()->json([
            'folder' => new FolderResource($folder),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $request->user();
        $folder = $this->folderService->findInWorkspace($workspace, $id);
        $name = $folder->name;

        $this->folderService->softDeleteCascade($workspace, $id);

        $this->activityLogService->log(
            $workspace,
            $user,
            'folder.trashed',
            $this->activityLogService->byUser('trashed', 'Folder “'.$name.'”', $user),
            'folder',
            $id,
        );

        return response()->json(['message' => 'Folder moved to trash.']);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $request->user();

        $folder = $this->folderService->restoreCascade($workspace, $id);

        $this->activityLogService->log(
            $workspace,
            $user,
            'folder.restored',
            $this->activityLogService->byUser('restored', 'Folder “'.$folder->name.'”', $user),
            'folder',
            $folder->id,
        );

        return response()->json([
            'message' => 'Folder restored.',
            'folder' => new FolderResource($folder),
        ]);
    }

    public function forceDestroy(Request $request, int $id): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $request->user();
        $folder = $this->folderService->findTrashedInWorkspace($workspace, $id);
        $name = $folder->name;

        $this->folderService->forceDeleteCascade($workspace, $id);

        $this->activityLogService->log(
            $workspace,
            $user,
            'folder.deleted',
            $this->activityLogService->byUser('permanently deleted', 'Folder “'.$name.'”', $user),
            'folder',
            $id,
        );

        return response()->json([
            'message' => 'Folder permanently deleted.',
        ]);
    }
}
