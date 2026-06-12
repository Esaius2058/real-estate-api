<?php

namespace App\Http\Requests\Property;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePropertyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title'       => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'price'       => ['sometimes', 'numeric'],
            'status'      => ['sometimes', 'string'],
            'location'    => ['sometimes', 'string'],
            'city'        => ['sometimes', 'string'],
            'bedrooms'    => ['sometimes', 'integer'],
            'baths'       => ['sometimes', 'integer'],
            'sqft'        => ['sometimes', 'integer'],
            'images'      => ['sometimes', 'array'],
            'images.*'    => ['string', 'url'],
        ];
    }
}
