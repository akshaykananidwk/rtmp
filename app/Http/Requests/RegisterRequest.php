<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'business_name' => ['nullable', 'string', 'max:100'],
            // Accounts are global, not per tenant, so the address must be unique everywhere.
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            // The same policy the panel enforces everywhere else: a self-service account
            // must not be allowed a weaker password than one an admin creates.
            'password' => ['required', 'confirmed', Password::defaults()],
            'terms' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'terms.accepted' => 'Please accept the terms to create an account.',
            'email.unique' => 'An account with this email already exists. Try signing in instead.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }
}
