<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Domain\Wallet\Exceptions\DomainRuleException;

final readonly class Money
{
    private function __construct(
        private int $amountCents,
    ) {}

    public static function fromCents(int $cents): self
    {
        if ($cents <= 0) {
            throw new DomainRuleException('O valor da operação deve ser maior que zero.');
        }

        return new self($cents);
    }

    public static function fromBalanceCents(int $cents): self
    {
        return new self($cents);
    }

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

    public function cents(): int
    {
        return $this->amountCents;
    }

    public function isNegative(): bool
    {
        return $this->amountCents < 0;
    }

    public function add(self $other): self
    {
        return new self($this->amountCents + $other->amountCents);
    }

    public function subtract(self $other): self
    {
        return new self($this->amountCents - $other->amountCents);
    }

    public function formatBrl(): string
    {
        $prefix = $this->amountCents < 0 ? '-R$ ' : 'R$ ';
        $absoluteCents = abs($this->amountCents);
        $reais = intdiv($absoluteCents, 100);
        $centavos = $absoluteCents % 100;

        return $prefix.number_format($reais, 0, ',', '.').','.str_pad((string) $centavos, 2, '0', STR_PAD_LEFT);
    }
}

