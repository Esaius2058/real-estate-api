<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

class GenerateUploadUrlRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Set to true assuming the route is protected by auth:sanctum middleware
        return true; 
    }

    public function rules(): array
    {
        return [
            'file_name'     => ['required', 'string', 'max:255'],
            'mime_type'     => ['required', 'string', 'max:100'],
            'document_type' => ['required', 'string'],
        ];
    }
}