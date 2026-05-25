<?php

namespace App\Http\Requests\Agency;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAgencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Security: Ensure the user actually belongs to the agency they are trying to update.
        // Assuming you only want agency admins/owners to edit this, you can add role checks here later.
        $agency = $this->route('agency');
        return $agency && $agency->id === auth()->user()->agency_id;
    }

    public function rules(): array
    {
        return [
            'name'     => ['sometimes', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }
}