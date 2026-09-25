<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Folder;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssetService
{
    public function list(Workspace $workspace, array $filters = []): Collection
    {
        $query = Asset::query()->where('workspace_id', $workspace->id);

        if (Arr::get($filters, 'trash')) {
            $query->onlyTrashed()
                // Files removed with a folder stay hidden (restored/deleted with that folder).
                // Files deleted on their own stay visible even if their folder is later trashed.
                ->whereNull('deleted_with_folder_id');
        } elseif (Arr::exists($filters, 'folder_id')) {
            $folderId = Arr::get($filters, 'folder_id');

            if (blank($folderId)) {
                $query->whereNull('folder_id');
            } else {
                $query->where('folder_id', (int) $folderId);
            }
        }

        if (filled(Arr::get($filters, 'q'))) {
            $query->where('name', 'ilike', '%'.Arr::get($filters, 'q').'%');
        }

        if (Arr::get($filters, 'sort', 'updated_desc') === 'name_asc') {
            $query->orderBy('name');
        } else {
            $query->orderByDesc('updated_at');
        }

        return $query->get();
    }

    public function create(Workspace $workspace, array $data, UploadedFile $file): Asset
    {
        $folderId = Arr::get($data, 'folder_id');

        if (! is_null($folderId)) {
            $this->assertFolderInWorkspace($workspace, $folderId);
        }

        $desiredName = Arr::get($data, 'name');
        if (blank($desiredName)) {
            $desiredName = $file->getClientOriginalName() ?: 'file';
        }

        $type = Arr::get($data, 'type');
        if (! in_array($type, ['image', 'video', 'logo', 'document', 'font'], true)) {
            $type = $this->detectType($file);
        }

        $url = $this->storeFile($workspace, $file);

        return Asset::create([
            'workspace_id' => $workspace->id,
            'folder_id' => $folderId,
            'name' => $this->uniqueNameInFolder($workspace, $folderId, (string) $desiredName),
            'type' => $type,
            'url' => $url,
            ...$this->aiFieldsFromData($data),
        ]);
    }

    public function createFromUrl(Workspace $workspace, array $data): Asset
    {
        $folderId = Arr::get($data, 'folder_id');

        if (! is_null($folderId)) {
            $this->assertFolderInWorkspace($workspace, $folderId);
        }

        $name = trim((string) Arr::get($data, 'name'));
        $type = (string) Arr::get($data, 'type');
        $url = trim((string) Arr::get($data, 'url'));

        if ($name === '' || $type === '' || $url === '') {
            throw ValidationException::withMessages([
                'name' => ['Name, type, and URL are required.'],
            ]);
        }

        if (! Str::startsWith(Str::lower($url), ['http://', 'https://'])) {
            throw ValidationException::withMessages([
                'url' => ['URL must start with http:// or https://.'],
            ]);
        }

        return Asset::create([
            'workspace_id' => $workspace->id,
            'folder_id' => $folderId,
            'name' => $this->uniqueNameInFolder($workspace, $folderId, $name),
            'type' => $type,
            'url' => $url,
            ...$this->aiFieldsFromData($data),
        ]);
    }

    /**
     * @return array{tags?: list<string>|null, description?: string|null, usage_suggestion?: string|null}
     */
    private function aiFieldsFromData(array $data): array
    {
        $fields = [];

        if (Arr::exists($data, 'tags')) {
            $tags = Arr::get($data, 'tags');
            $fields['tags'] = is_array($tags)
                ? array_values(array_filter(array_map(
                    fn ($tag) => is_string($tag) ? trim($tag) : '',
                    $tags
                )))
                : null;
        }

        if (Arr::exists($data, 'description')) {
            $fields['description'] = Arr::get($data, 'description');
        }

        if (Arr::exists($data, 'usage_suggestion')) {
            $fields['usage_suggestion'] = Arr::get($data, 'usage_suggestion');
        }

        return $fields;
    }

    public function update(Workspace $workspace, int $id, array $data, ?UploadedFile $file = null): Asset
    {
        $asset = $this->findInWorkspace($workspace, $id);

        if (Arr::exists($data, 'folder_id')) {
            $folderId = Arr::get($data, 'folder_id');

            if (! is_null($folderId)) {
                $this->assertFolderInWorkspace($workspace, $folderId);
            }

            $asset->folder_id = $folderId;
        }

        if (Arr::exists($data, 'name')) {
            $asset->name = Arr::get($data, 'name');
        }

        if (Arr::exists($data, 'tags')) {
            $tags = Arr::get($data, 'tags');
            $asset->tags = is_array($tags)
                ? array_values(array_filter(array_map(
                    fn ($tag) => is_string($tag) ? trim($tag) : '',
                    $tags
                )))
                : null;
        }

        if (Arr::exists($data, 'description')) {
            $asset->description = Arr::get($data, 'description');
        }

        if (Arr::exists($data, 'usage_suggestion')) {
            $asset->usage_suggestion = Arr::get($data, 'usage_suggestion');
        }

        if (filled($file)) {
            $this->deleteStoredFile($asset->url);
            $asset->type = $this->detectType($file);
            $asset->url = $this->storeFile($workspace, $file);
        }

        $asset->save();

        return $asset->fresh();
    }

    public function trash(Workspace $workspace, int $id): Asset
    {
        $asset = $this->findInWorkspace($workspace, $id);
        $asset->delete();

        return $asset;
    }

    public function restore(Workspace $workspace, int $id): Asset
    {
        $asset = $this->findTrashedInWorkspace($workspace, $id);

        $asset->deleted_with_folder_id = null;
        $asset->restore();

        return $asset->fresh();
    }

    public function forceDelete(Workspace $workspace, int $id): void
    {
        $asset = $this->findTrashedInWorkspace($workspace, $id);

        $this->deleteStoredFile($asset->url);
        $asset->forceDelete();
    }

    public function emptyTrash(Workspace $workspace): int
    {
        $assets = Asset::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->get();

        foreach ($assets as $asset) {
            $this->deleteStoredFile($asset->url);
            $asset->forceDelete();
        }

        return $assets->count();
    }

    public function findInWorkspace(Workspace $workspace, int $id): Asset
    {
        $asset = Asset::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($id)
            ->first();

        if ($asset) {
            return $asset;
        }

        if (Asset::withTrashed()->whereKey($id)->exists()) {
            abort(403, 'You do not have access to this asset.');
        }

        abort(404, 'Asset not found.');
    }

    public function findTrashedInWorkspace(Workspace $workspace, int $id): Asset
    {
        $asset = Asset::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->whereKey($id)
            ->first();

        if ($asset) {
            return $asset;
        }

        if (Asset::withTrashed()->where('workspace_id', $workspace->id)->whereKey($id)->exists()) {
            abort(404, 'Asset not found in trash.');
        }

        if (Asset::withTrashed()->whereKey($id)->exists()) {
            abort(403, 'You do not have access to this asset.');
        }

        abort(404, 'Asset not found.');
    }

    public function detectType(UploadedFile $file): string
    {
        $mime = Str::lower((string) $file->getMimeType());
        $extension = Str::lower((string) $file->getClientOriginalExtension());

        if (Str::startsWith($mime, 'video/') || in_array($extension, ['mp4', 'webm', 'mov'], true)) {
            return 'video';
        }

        if (
            Str::startsWith($mime, 'font/')
            || Str::contains($mime, 'font')
            || in_array($extension, ['ttf', 'otf', 'woff', 'woff2'], true)
        ) {
            return 'font';
        }

        if (
            in_array($extension, ['svg'], true)
            || $mime === 'image/svg+xml'
        ) {
            return 'logo';
        }

        if (Str::startsWith($mime, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return 'image';
        }

        return 'document';
    }

    private function storeFile(Workspace $workspace, UploadedFile $file): string
    {
        $directory = 'assets/'.$workspace->id;
        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $filename = Str::uuid()->toString().'.'.$extension;
        $path = $file->storeAs($directory, $filename, 'public');

        if (blank($path)) {
            abort(500, 'Could not store asset file.');
        }

        return Storage::disk('public')->url($path);
    }

    private function deleteStoredFile(?string $url): void
    {
        if (blank($url)) {
            return;
        }

        $prefix = rtrim((string) config('app.url'), '/').'/storage/';
        if (! Str::startsWith($url, $prefix)) {
            return;
        }

        $relativePath = Str::after($url, $prefix);
        if (filled($relativePath) && Storage::disk('public')->exists($relativePath)) {
            Storage::disk('public')->delete($relativePath);
        }
    }

    private function uniqueNameInFolder(Workspace $workspace, ?int $folderId, string $desired): string
    {
        $desired = trim($desired);
        if ($desired === '') {
            $desired = 'file';
        }

        $desired = Str::limit($desired, 255, '');

        $existing = Asset::query()
            ->where('workspace_id', $workspace->id)
            ->when(
                is_null($folderId),
                fn ($query) => $query->whereNull('folder_id'),
                fn ($query) => $query->where('folder_id', $folderId)
            )
            ->pluck('name')
            ->map(fn ($name) => Str::lower((string) $name))
            ->all();

        $taken = array_fill_keys($existing, true);

        if (! isset($taken[Str::lower($desired)])) {
            return $desired;
        }

        $extension = pathinfo($desired, PATHINFO_EXTENSION);
        $base = pathinfo($desired, PATHINFO_FILENAME);

        if ($base === '' || $base === '.') {
            $base = $desired;
            $extension = '';
        }

        $n = 1;
        while ($n < 10000) {
            $candidate = $extension !== ''
                ? "{$base} ({$n}).{$extension}"
                : "{$base} ({$n})";
            $candidate = Str::limit($candidate, 255, '');

            if (! isset($taken[Str::lower($candidate)])) {
                return $candidate;
            }

            $n++;
        }

        return Str::limit($base.' ('.Str::uuid()->toString().')'.($extension !== '' ? '.'.$extension : ''), 255, '');
    }

    private function assertFolderInWorkspace(Workspace $workspace, int $folderId): void
    {
        $exists = Folder::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($folderId)
            ->exists();

        if ($exists) {
            return;
        }

        if (Folder::withTrashed()->whereKey($folderId)->exists()) {
            abort(403, 'You do not have access to this folder.');
        }

        throw ValidationException::withMessages([
            'folder_id' => ['Folder not found in this workspace.'],
        ]);
    }
}
