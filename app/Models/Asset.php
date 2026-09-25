<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'folder_id',
    'deleted_with_folder_id',
    'name',
    'type',
    'url',
    'tags',
    'description',
    'usage_suggestion',
])]
class Asset extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'tags' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function deletedWithFolder(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'deleted_with_folder_id');
    }
}
