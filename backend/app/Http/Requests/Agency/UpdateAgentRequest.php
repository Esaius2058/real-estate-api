<?php

namespace App\Http\Requests\Agency;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Security: Only allow updates if the agent belongs to the auth user's agency
        $agent = $this->route('agent');
        return $agent && $agent->agency_id === auth()->user()->agency_id;
    }

    public function rules(): array
    {
        $agent = $this->route('agent');

        return [
            'name'     => ['sometimes', 'string', 'max:255'],
            'email'    => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($agent->id)],
            'password' => ['nullable', 'string', 'min:8'],
        ];
    }
}