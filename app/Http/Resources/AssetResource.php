<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Asset */
class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'folder_id' => $this->folder_id,
            'name' => $this->name,
            'type' => $this->type,
            'url' => $this->url,
            'tags' => $this->tags,
            'description' => $this->description,
            'usage_suggestion' => $this->usage_suggestion,
            'deleted_at' => $this->deleted_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
