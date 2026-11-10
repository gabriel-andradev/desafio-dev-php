<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates and normalizes user login credentials.
 */
class LoginRequest extends FormRequest
{
    /**
     * Determines if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalizes the email address before validation.
     */
    protected function prepareForValidation(): void
    {
        $rawEmail = (string) $this->input('email', '');

        $this->merge([
            'email' => strtolower(trim($rawEmail)),
        ]);
    }

    /**
     * Returns the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Returns custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Informe seu e-mail de acesso.',
            'email.email' => 'Informe um e-mail válido.',
            'password.required' => 'Informe sua senha.',
        ];
    }
}
