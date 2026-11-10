<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Domain\Wallet\Exceptions\DomainRuleException;

/**
 * Immutable Value Object representing monetary values in integer cents.
 */
final readonly class Money
{
    private function __construct(
        private int $amountCents,
    ) {}

    /**
     * Instantiates a positive transaction amount from integer cents.
     *
     * @throws DomainRuleException
     */
    public static function fromCents(int $cents): self
    {
        if ($cents <= 0) {
            throw new DomainRuleException('O valor da operação deve ser maior que zero.');
        }

        return new self($cents);
    }

    /**
     * Instantiates a wallet balance from integer cents, supporting negative balances.
     */
    public static function fromBalanceCents(int $cents): self
    {
        return new self($cents);
    }

    /**
     * Parses a Brazilian Real formatted input string into integer cents without floating-point conversion.
     *
     * @throws DomainRuleException
     */
    public static function fromBrlInput(string $rawInput): self
    {
        $cleaned = trim(str_replace(['R$', ' '], '', $rawInput));

        if ($cleaned === '') {
            throw new DomainRuleException('Informe um valor monetário válido.');
        }

        if (str_contains($cleaned, ',')) {
            $cleaned = str_replace('.', '', $cleaned);
            [$wholePart, $fractionPart] = array_pad(explode(',', $cleaned, 2), 2, '00');
        } elseif (str_contains($cleaned, '.')) {
            [$wholePart, $fractionPart] = array_pad(explode('.', $cleaned, 2), 2, '00');
        } else {
            $wholePart = $cleaned;
            $fractionPart = '00';
        }

        $fractionPart = substr(str_pad($fractionPart, 2, '0'), 0, 2);

        if (! ctype_digit($wholePart) || ! ctype_digit($fractionPart)) {
            throw new DomainRuleException('Formato de valor monetário inválido.');
        }

        $cents = ((int) $wholePart * 100) + (int) $fractionPart;

        return self::fromCents($cents);
    }

    /**
     * Returns the underlying value in integer cents.
     */
    public function cents(): int
    {
        return $this->amountCents;
    }

    /**
     * Determines whether the monetary value is negative.
     */
    public function isNegative(): bool
    {
        return $this->amountCents < 0;
    }

    /**
     * Adds another Money value and returns a new immutable instance.
     */
    public function add(self $other): self
    {
        return new self($this->amountCents + $other->amountCents);
    }

    /**
     * Subtracts another Money value and returns a new immutable instance.
     */
    public function subtract(self $other): self
    {
        return new self($this->amountCents - $other->amountCents);
    }

    /**
     * Formats the cent amount as a standard BRL currency string.
     */
    public function formatBrl(): string
    {
        $prefix = $this->amountCents < 0 ? '-R$ ' : 'R$ ';
        $absoluteCents = abs($this->amountCents);
        $reais = intdiv($absoluteCents, 100);
        $centavos = $absoluteCents % 100;

        return $prefix.number_format($reais, 0, ',', '.').','.str_pad((string) $centavos, 2, '0', STR_PAD_LEFT);
    }
}
