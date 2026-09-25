<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;
use Throwable;

class ActivityLogService
{
    public function log(
        Workspace $workspace,
        ?User $user,
        string $action,
        string $message,
        ?string $subjectType = null,
        ?int $subjectId = null,
    ): void {
        try {
            ActivityLog::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $user?->id,
                'action' => $action,
                'message' => $message,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Failed to write activity log', [
                'action' => $action,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, ActivityLog>
     */
    public function listForWorkspace(Workspace $workspace, int $limit = 50)
    {
        return ActivityLog::query()
            ->with('user:id,email,name')
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function byUser(string $verb, string $subjectLabel, ?User $user): string
    {
        $who = $user?->email ?? 'a user';

        return "{$subjectLabel} {$verb} by {$who}";
    }
}
