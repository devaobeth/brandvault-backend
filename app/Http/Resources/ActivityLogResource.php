<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ActivityLog */
class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'message' => $this->message,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'user_email' => $this->user?->email,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
