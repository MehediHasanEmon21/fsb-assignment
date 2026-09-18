<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique(Tenant::class, 'slug')->ignore($this->route('tenant')),
            ],
            'email' => ['sometimes', 'required', 'string', 'email:rfc', 'max:255'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive'])],
        ];
    }

    /**
     * @return array<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['name', 'slug', 'email', 'status'])) {
                    $validator->errors()->add('tenant', 'At least one tenant field is required.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if ($this->has('name')) {
            $values['name'] = $this->string('name')->trim()->toString();
        }

        if ($this->has('slug')) {
            $values['slug'] = Str::lower($this->string('slug')->trim()->toString());
        }

        if ($this->has('email')) {
            $values['email'] = Str::lower($this->string('email')->trim()->toString());
        }

        $this->merge($values);
    }
}
