<?php

namespace App\Http\Requests\Agency;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAgencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();
        $agency = $this->route('agency');

        // Security: User must belong to the agency AND have the admin role
        return $agency 
            && $user->agency_id === $agency->id 
            && strtolower($user->role) === 'admin';
    }

    public function rules(): array
    {
        return [
            'name'     => ['sometimes', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }
}