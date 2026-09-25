<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Brand */
class BrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'name' => $this->name,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'logo_url' => $this->logo_url,
            'default_font' => $this->default_font,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
