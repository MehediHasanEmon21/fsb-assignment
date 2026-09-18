<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:rfc'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [
            'email' => Str::lower($this->string('email')->trim()->toString()),
        ];

        if ($this->has('device_name')) {
            $normalized['device_name'] = $this->string('device_name')->trim()->toString();
        }

        $this->merge($normalized);
    }
}
