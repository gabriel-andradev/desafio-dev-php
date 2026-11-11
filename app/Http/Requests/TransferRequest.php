<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Wallet\Exceptions\DomainRuleException;
use App\Domain\Wallet\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates and normalizes peer-to-peer transfer requests.
 */
class TransferRequest extends FormRequest
{
    /**
     * Determines if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalizes recipient identifier and converts BRL amount input into integer cents.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('recipient')) {
            $rawRecipient = trim((string) $this->input('recipient'));
            $normalizedRecipient = str_contains($rawRecipient, '@')
                ? strtolower($rawRecipient)
                : (preg_replace('/\D/', '', $rawRecipient) ?? $rawRecipient);

            $this->merge(['recipient' => $normalizedRecipient]);
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
            'recipient' => ['required', 'string', 'max:255'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'description' => ['nullable', 'string', 'max:160'],
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
            'recipient.required' => 'Informe o CPF ou e-mail do destinatário.',
            'amount_cents.required' => 'Informe o valor da transferência.',
            'amount_cents.integer' => 'O valor da transferência é inválido.',
            'amount_cents.min' => 'O valor da transferência deve ser maior que zero.',
            'amount_cents.max' => 'O valor excede o limite permitido por operação.',
        ];
    }
}
