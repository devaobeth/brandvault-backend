<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadBrandLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'logo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif,svg', 'max:2048'],
        ];
    }
}
