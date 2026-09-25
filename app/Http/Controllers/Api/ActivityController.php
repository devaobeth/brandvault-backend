<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    use ResolvesWorkspace;

    public function __construct(private ActivityLogService $activityLogService) {}

    public function index(Request $request): JsonResponse
    {
        $logs = $this->activityLogService->listForWorkspace(
            $this->workspace($request)
        );

        return response()->json([
            'activities' => ActivityLogResource::collection($logs),
        ]);
    }
}
