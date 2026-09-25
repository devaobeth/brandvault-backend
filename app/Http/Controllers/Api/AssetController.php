<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\SuggestAssetTagsRequest;
use App\Http\Requests\UpdateAssetRequest;
use App\Http\Resources\AssetResource;
use App\Services\ActivityLogService;
use App\Services\AssetAiService;
use App\Services\AssetService;
use App\Services\FolderService;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AssetController extends Controller
{
    use ResolvesWorkspace;

    public function __construct(
        private AssetService $assetService,
        private FolderService $folderService,
        private AssetAiService $assetAiService,
        private WebhookService $webhookService,
        private ActivityLogService $activityLogService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'trash' => $request->boolean('trash'),
            'q' => $request->input('q'),
            'sort' => $request->input('sort', 'updated_desc'),
        ];

        if ($request->has('folder_id')) {
            $filters['folder_id'] = $request->input('folder_id');
        }

        $assets = $this->assetService->list(
            $this->workspace($request),
            $filters
        );

        return response()->json([
            'assets' => AssetResource::collection($assets),
        ]);
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $request->user();
        $data = $request->validated();

        $asset = $request->hasFile('file')
            ? $this->assetService->create($workspace, $data, $request->file('file'))
            : $this->assetService->createFromUrl($workspace, $data);

        $this->activityLogService->log(
            $workspace,
            $user,
            'asset.created',
            $this->activityLogService->byUser('created', 'Asset “'.$asset->name.'”', $user),
            'asset',
            $asset->id,
        );

        if ($this->webhookService->looksLikeAiTagSave($data)) {
            $this->activityLogService->log(
                $workspace,
                $user,
                'ai.tag_suggestion.saved',
                $this->activityLogService->byUser('saved with AI tags', 'Asset “'.$asset->name.'”', $user),
                'asset',
                $asset->id,
            );
            $this->webhookService->dispatch(
                WebhookService::EVENT_AI_TAG_SAVED,
                $asset->id,
                null,
                (string) $user->email,
            );
        }

        return response()->json([
            'asset' => new AssetResource($asset),
        ], 201);
    }

    public function update(UpdateAssetRequest $request, int $id): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $request->user();
        $data = $request->validated();

        $asset = $this->assetService->update(
            $workspace,
            $id,
            $data,
            $request->file('file')
        );

        $this->activityLogService->log(
            $workspace,
            $user,
            'asset.updated',
            $this->activityLogService->byUser('updated', 'Asset “'.$asset->name.'”', $user),
            'asset',
            $asset->id,
        );

        if ($this->webhookService->looksLikeAiTagSave($data)) {
            $this->activityLogService->log(
                $workspace,
                $user,
                'ai.tag_suggestion.saved',
                $this->activityLogService->byUser('saved with AI tags', 'Asset “'.$asset->name.'”', $user),
                'asset',
                $asset->id,
            );
            $this->webhookService->dispatch(
                WebhookService::EVENT_AI_TAG_SAVED,
                $asset->id,
                null,
                (string) $user->email,
            );
        }

        return response()->json([
            'asset' => new AssetResource($asset),
        ]);
    }

    public function trash(Request $request, int $id): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $request->user();

        $asset = $this->assetService->trash($workspace, $id);

        $this->activityLogService->log(
            $workspace,
            $user,
            'asset.trashed',
            $this->activityLogService->byUser('trashed', 'Asset “'.$asset->name.'”', $user),
            'asset',
            $asset->id,
        );

        return response()->json([
            'message' => 'Asset moved to trash.',
            'asset' => new AssetResource($asset),
        ]);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $request->user();

        $asset = $this->assetService->restore($workspace, $id);

        $this->activityLogService->log(
            $workspace,
            $user,
            'asset.restored',
            $this->activityLogService->byUser('restored', 'Asset “'.$asset->name.'”', $user),
            'asset',
            $asset->id,
        );

        $this->webhookService->dispatch(
            WebhookService::EVENT_ASSET_RESTORED,
            $asset->id,
            null,
            (string) $user->email,
        );

        return response()->json([
            'message' => 'Asset restored.',
            'asset' => new AssetResource($asset),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $request->user();
        $asset = $this->assetService->findTrashedInWorkspace($workspace, $id);
        $name = $asset->name;

        $this->assetService->forceDelete($workspace, $id);

        $this->activityLogService->log(
            $workspace,
            $user,
            'asset.deleted',
            $this->activityLogService->byUser('permanently deleted', 'Asset “'.$name.'”', $user),
            'asset',
            $id,
        );

        return response()->json([
            'message' => 'Asset permanently deleted.',
        ]);
    }

    public function emptyTrash(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $request->user();

        $folderCount = $this->folderService->emptyTrash($workspace);
        $assetCount = $this->assetService->emptyTrash($workspace);
        $deleted = $folderCount + $assetCount;

        $this->activityLogService->log(
            $workspace,
            $user,
            'trash.emptied',
            'Trash emptied by '.($user->email ?? 'a user').' ('.$deleted.' item'.($deleted === 1 ? '' : 's').')',
        );

        return response()->json([
            'message' => 'Trash emptied.',
            'deleted' => $deleted,
        ]);
    }

    public function generateTags(Request $request, int $id): JsonResponse
    {
        try {
            $suggestion = $this->assetAiService->suggestTags(
                $this->workspace($request),
                $id
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Could not generate AI tags.',
            ], 502);
        }

        return response()->json([
            'suggestion' => $suggestion,
        ]);
    }

    public function suggestTags(SuggestAssetTagsRequest $request): JsonResponse
    {
        try {
            $suggestion = $this->assetAiService->suggestFromDetails(
                $this->workspace($request),
                $request->safe()->only(['name', 'type', 'folder_id']),
                $request->file('file')
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Could not generate AI tags.',
            ], 502);
        }

        return response()->json([
            'suggestion' => $suggestion,
        ]);
    }
}
