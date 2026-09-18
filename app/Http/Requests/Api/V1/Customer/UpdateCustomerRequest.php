<?php

namespace App\Http\Requests\Api\V1\Customer;

use App\Models\Customer;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
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
            'email' => [
                'sometimes',
                'nullable',
                'string',
                'email:rfc',
                'max:255',
                Rule::unique(Customer::class, 'email')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($this->route('customer')),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('name')) {
            $normalized['name'] = $this->string('name')->trim()->toString();
        }

        if ($this->has('email')) {
            $normalized['email'] = $this->filled('email')
                ? Str::lower($this->string('email')->trim()->toString())
                : null;
        }

        if ($this->has('phone')) {
            $normalized['phone'] = $this->filled('phone')
                ? $this->string('phone')->trim()->toString()
                : null;
        }

        $this->merge($normalized);
    }
}
