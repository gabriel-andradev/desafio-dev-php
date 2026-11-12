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
     * The amount is only parsed here, so the min/max rules report the right message (e.g. above the
     * ceiling); text that is not a monetary value is left for the "integer" rule to reject.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('idempotency_key') && $this->hasHeader('X-Idempotency-Key')) {
            $this->merge(['idempotency_key' => (string) $this->header('X-Idempotency-Key')]);
        }

        if ($this->filled('recipient')) {
            $rawRecipient = trim((string) $this->input('recipient'));
            $normalizedRecipient = str_contains($rawRecipient, '@')
                ? strtolower($rawRecipient)
                : (preg_replace('/\D/', '', $rawRecipient) ?? $rawRecipient);

            $this->merge(['recipient' => $normalizedRecipient]);
        }

        if (! $this->has('amount_cents') && $this->filled('amount')) {
            try {
                $this->merge(['amount_cents' => Money::parseBrlToCents((string) $this->input('amount'))]);
            } catch (DomainRuleException) {
                $this->merge(['amount_cents' => 'invalid']);
            }
        }
    }

    /**
     * Returns the validation rules that apply to the request.
     * integer:strict rejects JSON booleans and numeric strings (true would otherwise become 1 cent).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'recipient' => ['required', 'string', 'max:255'],
            'amount_cents' => ['required', 'integer:strict', 'min:1', 'max:'.Money::MAX_OPERATION_CENTS],
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
            'recipient.required' => 'Informe o CPF ou e-mail do destinatário.',
            'amount_cents.required' => 'Informe o valor da transferência.',
            'amount_cents.integer' => 'O valor da transferência é inválido.',
            'amount_cents.min' => 'O valor da transferência deve ser maior que zero.',
            'amount_cents.max' => 'O valor excede o limite máximo permitido por operação.',
        ];
    }
}
