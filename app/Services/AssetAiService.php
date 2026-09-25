<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Folder;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AssetAiService
{
    private const MAX_IMAGE_BYTES = 4_000_000;

    public function __construct(
        private GeminiClient $gemini,
        private AssetService $assetService,
        private BrandService $brandService,
    ) {}

    /**
     * @return array{tags: list<string>, description: string, usage_suggestion: string}
     */
    public function suggestTags(Workspace $workspace, int $assetId): array
    {
        $asset = $this->assetService->findInWorkspace($workspace, $assetId);

        return $this->suggestFromDetails(
            $workspace,
            [
                'name' => $asset->name,
                'type' => $asset->type,
                'folder_id' => $asset->folder_id,
            ],
            $this->imageFromAsset($asset)
        );
    }

    /**
     * @param  array{name: string, type: string, folder_id?: int|null}  $details
     * @param  UploadedFile|array{mime: string, data: string}|null  $imageSource
     * @return array{tags: list<string>, description: string, usage_suggestion: string}
     */
    public function suggestFromDetails(
        Workspace $workspace,
        array $details,
        UploadedFile|array|null $imageSource = null,
    ): array {
        $folderName = null;
        $folderId = Arr::get($details, 'folder_id');

        if (! is_null($folderId)) {
            $folder = Folder::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey((int) $folderId)
                ->first();
            $folderName = $folder?->name;
        }

        $image = null;
        if ($imageSource instanceof UploadedFile) {
            $image = $this->imageFromUpload($imageSource);
        } elseif (is_array($imageSource)) {
            $image = $imageSource;
        }

        $brand = $this->brandService->getForWorkspace($workspace);
        $systemPrompt = $this->loadPrompt();
        $userPrompt = $this->buildUserPrompt(
            (string) Arr::get($details, 'name'),
            (string) Arr::get($details, 'type'),
            $folderName,
            $brand,
            $image !== null
        );

        $raw = $this->gemini->generateJson($systemPrompt, $userPrompt, $image);

        return $this->validateSuggestion($raw);
    }

    private function loadPrompt(): string
    {
        $path = base_path('../prompts/asset-tagging.md');

        if (! File::exists($path)) {
            $path = base_path('prompts/asset-tagging.md');
        }

        if (! File::exists($path)) {
            throw new RuntimeException('Asset tagging prompt file is missing.');
        }

        return File::get($path);
    }

    private function buildUserPrompt(
        string $name,
        string $type,
        ?string $folderName,
        $brand,
        bool $hasImage,
    ): string {
        $lines = [
            'Asset name: '.$name,
            'Asset type: '.$type,
            'Folder name: '.($folderName ?: '(none)'),
        ];

        if ($brand) {
            $lines[] = 'Brand name: '.$brand->name;
            $lines[] = 'Brand primary color: '.$brand->primary_color;
            $lines[] = 'Brand secondary color: '.$brand->secondary_color;
            if (filled($brand->default_font)) {
                $lines[] = 'Brand default font: '.$brand->default_font;
            }
        } else {
            $lines[] = 'Brand profile: (none)';
        }

        if ($hasImage) {
            $lines[] = 'An image of the asset is attached. Use what you see in the image together with the fields above.';
        }

        $lines[] = '';
        $lines[] = 'Respond with JSON matching the output schema.';

        return implode("\n", $lines);
    }

    /**
     * @return array{mime: string, data: string}|null
     */
    private function imageFromUpload(UploadedFile $file): ?array
    {
        $mime = Str::lower((string) $file->getMimeType());

        if (! Str::startsWith($mime, 'image/') || $mime === 'image/svg+xml') {
            return null;
        }

        $path = $file->getRealPath();
        if ($path === false || ! is_readable($path)) {
            return null;
        }

        $size = filesize($path);
        if ($size === false || $size > self::MAX_IMAGE_BYTES || $size < 1) {
            return null;
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        return [
            'mime' => $mime,
            'data' => base64_encode($bytes),
        ];
    }

    /**
     * @return array{mime: string, data: string}|null
     */
    private function imageFromAsset(Asset $asset): ?array
    {
        if (! in_array($asset->type, ['image', 'logo'], true)) {
            return null;
        }

        $relative = $this->storagePathFromUrl($asset->url);
        if ($relative && Storage::disk('public')->exists($relative)) {
            $bytes = Storage::disk('public')->get($relative);
            if ($bytes === null || strlen($bytes) > self::MAX_IMAGE_BYTES || $bytes === '') {
                return null;
            }
            $mime = Storage::disk('public')->mimeType($relative) ?: 'image/jpeg';
            if (! Str::startsWith((string) $mime, 'image/') || $mime === 'image/svg+xml') {
                return null;
            }

            return [
                'mime' => (string) $mime,
                'data' => base64_encode($bytes),
            ];
        }

        // Remote HTTPS image — fetch bytes without sending the URL text to the model prompt.
        if (Str::startsWith(Str::lower($asset->url), 'https://')) {
            try {
                $response = Http::timeout(15)->get($asset->url);
                if (! $response->successful()) {
                    return null;
                }
                $bytes = $response->body();
                if ($bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
                    return null;
                }
                $mime = Str::lower((string) ($response->header('Content-Type') ?: 'image/jpeg'));
                $mime = Str::before($mime, ';');
                if (! Str::startsWith($mime, 'image/') || $mime === 'image/svg+xml') {
                    return null;
                }

                return [
                    'mime' => $mime,
                    'data' => base64_encode($bytes),
                ];
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function storagePathFromUrl(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $prefix = rtrim((string) config('app.url'), '/').'/storage/';
        if (! Str::startsWith($url, $prefix)) {
            return null;
        }

        $relative = Str::after($url, $prefix);

        return filled($relative) ? $relative : null;
    }

    /**
     * @return array{tags: list<string>, description: string, usage_suggestion: string}
     */
    private function validateSuggestion(array $raw): array
    {
        $validator = Validator::make($raw, [
            'tags' => ['required', 'array', 'min:1', 'max:12'],
            'tags.*' => ['required', 'string', 'max:64'],
            'description' => ['required', 'string', 'max:500'],
            'usage_suggestion' => ['required', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages([
                'suggestion' => ['AI response failed validation and was not saved.'],
                ...$validator->errors()->toArray(),
            ]);
        }

        /** @var array{tags: list<string>, description: string, usage_suggestion: string} $validated */
        $validated = $validator->validated();

        $validated['tags'] = array_values(array_unique(array_map(
            fn (string $tag) => trim(mb_strtolower($tag)),
            $validated['tags']
        )));

        $validated['description'] = trim($validated['description']);
        $validated['usage_suggestion'] = trim($validated['usage_suggestion']);

        if ($validated['tags'] === [] || $validated['description'] === '' || $validated['usage_suggestion'] === '') {
            throw ValidationException::withMessages([
                'suggestion' => ['AI response was empty after cleanup and was not saved.'],
            ]);
        }

        return $validated;
    }
}
