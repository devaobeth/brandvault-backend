<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BrandService
{
    public function getForWorkspace(Workspace $workspace): ?Brand
    {
        return $workspace->brand;
    }

    public function create(Workspace $workspace, array $data): Brand
    {
        if (filled($workspace->brand)) {
            throw ValidationException::withMessages([
                'name' => ['Brand already exists for this workspace.'],
            ]);
        }

        return $workspace->brand()->create($data);
    }

    public function update(Workspace $workspace, array $data): Brand
    {
        $brand = $workspace->brand;

        if (blank($brand)) {
            abort(404, 'Brand not found.');
        }

        $brand->update($data);

        return $brand->fresh();
    }

    public function uploadLogo(Workspace $workspace, UploadedFile $file): Brand
    {
        $brand = $workspace->brand;

        if (blank($brand)) {
            abort(404, 'Brand not found. Create the brand kit first.');
        }

        $directory = 'brands/'.$workspace->id;
        $filename = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs($directory, $filename, 'public');

        if (blank($path)) {
            abort(500, 'Could not store logo file.');
        }

        $this->deleteStoredLogo($brand->logo_url);

        $brand->update([
            'logo_url' => Storage::disk('public')->url($path),
        ]);

        return $brand->fresh();
    }

    private function deleteStoredLogo(?string $logoUrl): void
    {
        if (blank($logoUrl)) {
            return;
        }

        $prefix = rtrim((string) config('app.url'), '/').'/storage/';
        if (! Str::startsWith($logoUrl, $prefix)) {
            return;
        }

        $relativePath = Str::after($logoUrl, $prefix);
        if (filled($relativePath) && Storage::disk('public')->exists($relativePath)) {
            Storage::disk('public')->delete($relativePath);
        }
    }
}
