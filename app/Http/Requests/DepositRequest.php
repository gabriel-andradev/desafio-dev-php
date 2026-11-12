<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Wallet\Exceptions\DomainRuleException;
use App\Domain\Wallet\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates and normalizes deposit requests in cents or BRL string format.
 */
class DepositRequest extends FormRequest
{
    /**
     * Determines if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Converts BRL formatted input into integer cents when amount_cents is not provided directly.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('idempotency_key') && $this->hasHeader('X-Idempotency-Key')) {
            $this->merge(['idempotency_key' => (string) $this->header('X-Idempotency-Key')]);
        }

        if (! $this->has('amount_cents') && $this->filled('amount')) {
            try {
                $money = Money::fromBrlInput((string) $this->input('amount'));
                $this->merge(['amount_cents' => $money->cents()]);
            } catch (DomainRuleException) {
                $this->merge(['amount_cents' => 0]);
            }
        }
    }

    /**
     * Returns the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'amount_cents' => ['required', 'integer', 'min:1', 'max:'.Money::MAX_OPERATION_CENTS],
            'description' => ['nullable', 'string', 'max:140'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
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
            'amount_cents.required' => 'Informe o valor do depósito.',
            'amount_cents.integer' => 'O valor do depósito é inválido.',
            'amount_cents.min' => 'O valor do depósito deve ser maior que zero.',
            'amount_cents.max' => 'O valor excede o limite permitido por operação.',
        ];
    }
}
