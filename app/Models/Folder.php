<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['workspace_id', 'parent_id', 'name'])]
class Folder extends Model
{
    use SoftDeletes;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function depth(): int
    {
        $depth = 1;
        $folder = $this->parent;

        while ($folder) {
            $depth++;
            $folder = $folder->parent;
        }

        return $depth;
    }
}
