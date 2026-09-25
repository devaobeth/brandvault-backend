<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $tags = $this->input('tags');
        if (is_string($tags)) {
            $decoded = json_decode($tags, true);
            if (is_array($decoded)) {
                $this->merge(['tags' => $decoded]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'file' => [
                'sometimes',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,webp,gif,svg,mp4,webm,mov,pdf,doc,docx,txt,rtf,ttf,otf,woff,woff2',
            ],
            'folder_id' => ['nullable', 'integer'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:20'],
            'tags.*' => ['required', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'usage_suggestion' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
