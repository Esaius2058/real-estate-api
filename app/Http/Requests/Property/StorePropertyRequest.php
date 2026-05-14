<?php

namespace App\Http\Requests\Property;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePropertyRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            'title'          => ['required', 'string', 'max:255'],
            'price'          => ['required', 'numeric', 'min:0'],
            'bedrooms'       => ['required', 'integer', 'min:0'],
            'city'           => ['required', 'string', 'max:100'],
            'status'         => ['required', 'in:available,sold,escrow'],
        ];
    }
}
