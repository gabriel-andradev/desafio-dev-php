<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validates and sanitizes incoming user registration payloads.
 */
class RegisterRequest extends FormRequest
{
    /**
     * Determines if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalizes CPF digits, email casing, and name whitespace before validation.
     */
    protected function prepareForValidation(): void
    {
        $rawCpf = (string) $this->input('cpf', '');
        $rawEmail = (string) $this->input('email', '');
        $rawName = (string) $this->input('name', '');

        $this->merge([
            'name' => trim($rawName),
            'email' => strtolower(trim($rawEmail)),
            'cpf' => preg_replace('/\D/', '', $rawCpf) ?? '',
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
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'cpf' => ['required', 'string', 'digits:11', 'unique:users,cpf'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
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
            'name.required' => 'Informe seu nome completo.',
            'name.min' => 'O nome deve ter pelo menos 3 caracteres.',
            'name.max' => 'O nome deve ter no máximo 100 caracteres.',
            'password.max' => 'A senha deve ter no máximo 72 caracteres.',
            'email.required' => 'Informe seu endereço de e-mail.',
            'email.email' => 'Informe um endereço de e-mail válido.',
            'email.unique' => 'Este e-mail já está cadastrado.',
            'cpf.required' => 'Informe seu CPF.',
            'cpf.digits' => 'O CPF deve conter exatamente 11 dígitos numéricos.',
            'cpf.unique' => 'Este CPF já está vinculado a uma conta.',
            'password.required' => 'Crie uma senha de acesso.',
            'password.min' => 'A senha deve conter pelo menos 8 caracteres.',
        ];
    }

    /**
     * Returns the validated registration fields in the exact shape the AuthService expects.
     *
     * @return array{name: string, email: string, cpf: string, password: string}
     */
    public function registrationData(): array
    {
        $this->validated();

        return [
            'name' => $this->string('name')->toString(),
            'email' => $this->string('email')->toString(),
            'cpf' => $this->string('cpf')->toString(),
            'password' => $this->string('password')->toString(),
        ];
    }

    /**
     * A duplicate e-mail/CPF is a conflict with existing data (409, as in the Node API), not a malformed field.
     * Web forms keep Laravel's default redirect-back behaviour.
     */
    protected function failedValidation(Validator $validator): void
    {
        $onlyUniqueFailures = collect($validator->failed())
            ->every(fn (array $rules): bool => array_keys($rules) === ['Unique']);

        if ($this->expectsJson() && $onlyUniqueFailures) {
            throw new HttpResponseException(response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 409));
        }

        parent::failedValidation($validator);
    }
}
