<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBrandRequest;
use App\Http\Requests\UpdateBrandRequest;
use App\Http\Requests\UploadBrandLogoRequest;
use App\Http\Resources\BrandResource;
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
        $brand = $this->brandService->create(
            $this->workspace($request),
            $request->validated()
        );

        return response()->json([
            'brand' => new BrandResource($brand),
        ], 201);
    }

    public function update(UpdateBrandRequest $request): JsonResponse
    {
        $brand = $this->brandService->update(
            $this->workspace($request),
            $request->validated()
        );

        $this->webhookService->dispatch(
            WebhookService::EVENT_BRAND_UPDATED,
            null,
            $brand->id,
            (string) $request->user()->email,
        );

        return response()->json([
            'brand' => new BrandResource($brand),
        ]);
    }

    public function uploadLogo(UploadBrandLogoRequest $request): JsonResponse
    {
        $brand = $this->brandService->uploadLogo(
            $this->workspace($request),
            $request->file('logo')
        );

        $this->webhookService->dispatch(
            WebhookService::EVENT_BRAND_UPDATED,
            null,
            $brand->id,
            (string) $request->user()->email,
        );

        return response()->json([
            'brand' => new BrandResource($brand),
            'message' => 'Logo uploaded.',
        ]);
    }
}
