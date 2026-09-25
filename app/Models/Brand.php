<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'name',
    'primary_color',
    'secondary_color',
    'logo_url',
    'default_font',
])]
class Brand extends Model
{
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
