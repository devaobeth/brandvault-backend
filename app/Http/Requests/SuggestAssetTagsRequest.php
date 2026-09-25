<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SuggestAssetTagsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:image,video,logo,document,font'],
            'folder_id' => ['nullable', 'integer'],
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,webp,gif,svg,mp4,webm,mov,pdf,doc,docx,txt,rtf,ttf,otf,woff,woff2',
            ],
        ];
    }
}
