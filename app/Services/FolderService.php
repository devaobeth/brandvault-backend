<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Folder;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FolderService
{
    private const MAX_DEPTH = 3;

    public function __construct(private AssetService $assetService) {}

    public function list(Workspace $workspace, ?int $parentId = null): Collection
    {
        $query = Folder::query()
            ->where('workspace_id', $workspace->id);

        if (! is_null($parentId)) {
            $query->where('parent_id', $parentId);
        } else {
            $query->whereNull('parent_id');
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Top-level trash only (like Windows Recycle Bin): a deleted folder whose
     * parent is not also in trash. Nested contents restore/delete with it.
     */
    public function listTrash(Workspace $workspace, array $filters = []): Collection
    {
        $trashedIds = Folder::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->pluck('id')
            ->all();

        $query = Folder::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->where(function ($builder) use ($trashedIds) {
                $builder->whereNull('parent_id');

                if ($trashedIds !== []) {
                    $builder->orWhereNotIn('parent_id', $trashedIds);
                }
            });

        if (filled(Arr::get($filters, 'q'))) {
            $query->where('name', 'ilike', '%'.Arr::get($filters, 'q').'%');
        }

        if (Arr::get($filters, 'sort', 'updated_desc') === 'name_asc') {
            $query->orderBy('name');
        } else {
            $query->orderByDesc('deleted_at');
        }

        return $query->get();
    }

    public function create(Workspace $workspace, array $data): Folder
    {
        $parentId = Arr::get($data, 'parent_id');
        $parent = null;

        if (! is_null($parentId)) {
            $parent = $this->findInWorkspace($workspace, $parentId);

            if ($parent->depth() >= self::MAX_DEPTH) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Maximum folder depth of '.self::MAX_DEPTH.' reached.'],
                ]);
            }
        }

        return Folder::create([
            'workspace_id' => $workspace->id,
            'parent_id' => $parent?->id,
            'name' => Arr::get($data, 'name'),
        ]);
    }

    public function update(Workspace $workspace, int $id, array $data): Folder
    {
        $folder = $this->findInWorkspace($workspace, $id);

        if (Arr::exists($data, 'parent_id')) {
            $this->applyParentChange($workspace, $folder, Arr::get($data, 'parent_id'));
        }

        $folder->fill(Arr::only($data, ['name']));
        $folder->save();

        return $folder->fresh();
    }

    public function softDeleteCascade(Workspace $workspace, int $id): void
    {
        $folder = $this->findInWorkspace($workspace, $id);

        DB::transaction(function () use ($folder, $workspace) {
            $folderIds = $this->collectDescendantIds($folder);
            $folderIds[] = $folder->id;

            Asset::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('folder_id', $folderIds)
                ->update(['deleted_with_folder_id' => $folder->id]);

            Asset::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('folder_id', $folderIds)
                ->delete();

            Folder::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('id', $folderIds)
                ->orderByDesc('id')
                ->get()
                ->each->delete();
        });
    }

    public function restoreCascade(Workspace $workspace, int $id): Folder
    {
        $folder = $this->findTrashedInWorkspace($workspace, $id);

        DB::transaction(function () use ($folder, $workspace) {
            $folderIds = $this->collectTrashedDescendantIds($folder);
            $folderIds[] = $folder->id;

            Folder::onlyTrashed()
                ->where('workspace_id', $workspace->id)
                ->whereIn('id', $folderIds)
                ->orderBy('id')
                ->get()
                ->each->restore();

            // Only restore files that were removed with this folder — not ones deleted earlier on their own.
            Asset::onlyTrashed()
                ->where('workspace_id', $workspace->id)
                ->where('deleted_with_folder_id', $folder->id)
                ->get()
                ->each(function (Asset $asset) {
                    $asset->deleted_with_folder_id = null;
                    $asset->restore();
                });
        });

        return $folder->fresh();
    }

    public function forceDeleteCascade(Workspace $workspace, int $id): void
    {
        $folder = $this->findTrashedInWorkspace($workspace, $id);

        DB::transaction(function () use ($folder, $workspace) {
            $folderIds = $this->collectTrashedDescendantIds($folder);
            $folderIds[] = $folder->id;

            $assets = Asset::onlyTrashed()
                ->where('workspace_id', $workspace->id)
                ->where('deleted_with_folder_id', $folder->id)
                ->get();

            foreach ($assets as $asset) {
                $this->assetService->forceDelete($workspace, $asset->id);
            }

            Folder::onlyTrashed()
                ->where('workspace_id', $workspace->id)
                ->whereIn('id', $folderIds)
                ->orderByDesc('id')
                ->get()
                ->each->forceDelete();
        });
    }

    public function emptyTrash(Workspace $workspace): int
    {
        $roots = $this->listTrash($workspace);
        $count = $roots->count();

        foreach ($roots as $folder) {
            $this->forceDeleteCascade($workspace, $folder->id);
        }

        return $count;
    }

    public function findInWorkspace(Workspace $workspace, int $id): Folder
    {
        $folder = Folder::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($id)
            ->first();

        if ($folder) {
            return $folder;
        }

        if (Folder::withTrashed()->whereKey($id)->exists()) {
            abort(403, 'You do not have access to this folder.');
        }

        abort(404, 'Folder not found.');
    }

    public function findTrashedInWorkspace(Workspace $workspace, int $id): Folder
    {
        $folder = Folder::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->whereKey($id)
            ->first();

        if ($folder) {
            return $folder;
        }

        if (Folder::withTrashed()->where('workspace_id', $workspace->id)->whereKey($id)->exists()) {
            abort(404, 'Folder not found in trash.');
        }

        if (Folder::withTrashed()->whereKey($id)->exists()) {
            abort(403, 'You do not have access to this folder.');
        }

        abort(404, 'Folder not found.');
    }

    private function collectTrashedDescendantIds(Folder $folder): array
    {
        return Folder::onlyTrashed()
            ->where('parent_id', $folder->id)
            ->get()
            ->flatMap(function (Folder $child) {
                return collect([$child->id])->merge($this->collectTrashedDescendantIds($child));
            })
            ->all();
    }

    private function applyParentChange(Workspace $workspace, Folder $folder, ?int $parentId): void
    {
        if (is_null($parentId)) {
            $folder->parent_id = null;

            return;
        }

        if ($parentId === $folder->id) {
            throw ValidationException::withMessages([
                'parent_id' => ['A folder cannot be its own parent.'],
            ]);
        }

        $parent = $this->findInWorkspace($workspace, $parentId);

        if ($this->isDescendant($folder, $parent)) {
            throw ValidationException::withMessages([
                'parent_id' => ['Cannot move a folder into its own descendant.'],
            ]);
        }

        if ($parent->depth() + 1 > self::MAX_DEPTH) {
            throw ValidationException::withMessages([
                'parent_id' => ['Maximum folder depth of '.self::MAX_DEPTH.' reached.'],
            ]);
        }

        $folder->parent_id = $parent->id;
    }

    private function collectDescendantIds(Folder $folder): array
    {
        return Folder::query()
            ->where('parent_id', $folder->id)
            ->get()
            ->flatMap(function (Folder $child) {
                return collect([$child->id])->merge($this->collectDescendantIds($child));
            })
            ->all();
    }

    private function isDescendant(Folder $ancestor, Folder $candidate): bool
    {
        $current = $candidate;

        while (filled($current->parent_id)) {
            if ((int) $current->parent_id === (int) $ancestor->id) {
                return true;
            }

            $current = $current->parent;

            if (blank($current)) {
                break;
            }
        }

        return false;
    }
}
