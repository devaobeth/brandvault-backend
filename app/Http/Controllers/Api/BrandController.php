<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBrandRequest;
use App\Http\Requests\UpdateBrandRequest;
use App\Http\Requests\UploadBrandLogoRequest;
use App\Http\Resources\BrandResource;
use App\Services\ActivityLogService;
use App\Services\BrandService;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    use ResolvesWorkspace;

    public function __construct(
        private BrandService $brandService,
        private WebhookService $webhookService,
        private ActivityLogService $activityLogService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $brand = $this->brandService->getForWorkspace($this->workspace($request));

        if (blank($brand)) {
            return response()->json(['message' => 'Brand not found.'], 404);
        }

        return response()->json([
            'brand' => new BrandResource($brand),
        ]);
    }

    public function store(StoreBrandRequest $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $request->user();

        $brand = $this->brandService->create($workspace, $request->validated());

        $this->activityLogService->log(
            $workspace,
            $user,
            'brand.created',
            $this->activityLogService->byUser('created', 'Brand “'.$brand->name.'”', $user),
            'brand',
            $brand->id,
        );

        return response()->json([
            'brand' => new BrandResource($brand),
        ], 201);
    }

    public function update(UpdateBrandRequest $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $request->user();

        $brand = $this->brandService->update($workspace, $request->validated());

        $this->activityLogService->log(
            $workspace,
            $user,
            'brand.updated',
            $this->activityLogService->byUser('updated', 'Brand “'.$brand->name.'”', $user),
            'brand',
            $brand->id,
        );

        $this->webhookService->dispatch(
            WebhookService::EVENT_BRAND_UPDATED,
            null,
            $brand->id,
            (string) $user->email,
        );

        return response()->json([
            'brand' => new BrandResource($brand),
        ]);
    }

    public function uploadLogo(UploadBrandLogoRequest $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $request->user();

        $brand = $this->brandService->uploadLogo($workspace, $request->file('logo'));

        $this->activityLogService->log(
            $workspace,
            $user,
            'brand.logo_uploaded',
            'Logo uploaded for brand “'.$brand->name.'” by '.($user->email ?? 'a user'),
            'brand',
            $brand->id,
        );

        $this->webhookService->dispatch(
            WebhookService::EVENT_BRAND_UPDATED,
            null,
            $brand->id,
            (string) $user->email,
        );

        return response()->json([
            'brand' => new BrandResource($brand),
            'message' => 'Logo uploaded.',
        ]);
    }
}
