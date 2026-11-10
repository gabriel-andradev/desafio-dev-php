<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Wallet\Exceptions\DomainRuleException;
use App\Domain\Wallet\Money;
use App\Domain\Wallet\TransactionDomainRules;
use App\Domain\Wallet\TransactionStatus;
use App\Domain\Wallet\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Coordinates atomic wallet operations, pessimistic locking, and ledger persistence.
 */
class WalletService
{
    /**
     * Retrieves the complete wallet summary, ledger history, and available recipients for a user.
     *
     * @return array{
     *     wallet_id: int,
     *     balance_cents: int,
     *     formatted_balance: string,
     *     is_negative: bool,
     *     total_deposits_cents: int,
     *     total_sent_cents: int,
     *     transactions: Collection<int, Transaction>,
     *     recipients: Collection<int, User>
     * }
     */
    public function getWalletSummary(User $user): array
    {
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['balance_cents' => 0],
        );

        $transactions = Transaction::with(['senderWallet.user', 'receiverWallet.user'])
            ->where(function ($query) use ($wallet): void {
                $query->where('sender_wallet_id', $wallet->id)
                    ->orWhere('receiver_wallet_id', $wallet->id);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $totalDepositsCents = (int) Transaction::where('receiver_wallet_id', $wallet->id)
            ->where('type', TransactionType::DEPOSIT->value)
            ->where('status', TransactionStatus::COMPLETED->value)
            ->sum('amount_cents');

        $totalSentCents = (int) Transaction::where('sender_wallet_id', $wallet->id)
            ->where('type', TransactionType::TRANSFER->value)
            ->where('status', TransactionStatus::COMPLETED->value)
            ->sum('amount_cents');

        $recipients = User::where('id', '!=', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'cpf']);

        $balanceMoney = Money::fromBalanceCents($wallet->balance_cents);

        return [
            'wallet_id' => $wallet->id,
            'balance_cents' => $wallet->balance_cents,
            'formatted_balance' => $balanceMoney->formatBrl(),
            'is_negative' => $balanceMoney->isNegative(),
            'total_deposits_cents' => $totalDepositsCents,
            'total_sent_cents' => $totalSentCents,
            'transactions' => $transactions,
            'recipients' => $recipients,
        ];
    }

    /**
     * Credits a deposit into the user's wallet atomically, offsetting any existing negative balance.
     *
     * @throws DomainRuleException
     */
    public function deposit(User $user, int $amountCents, ?string $description = null): Transaction
    {
        $depositMoney = Money::fromCents($amountCents);

        return DB::transaction(function () use ($user, $depositMoney, $description): Transaction {
            $wallet = Wallet::where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $newBalanceCents = TransactionDomainRules::calculateBalanceAfterDeposit(
                currentBalanceCents: $wallet->balance_cents,
                depositAmount: $depositMoney,
            );

            $wallet->update([
                'balance_cents' => $newBalanceCents,
            ]);

            return Transaction::create([
                'type' => TransactionType::DEPOSIT->value,
                'status' => TransactionStatus::COMPLETED->value,
                'amount_cents' => $depositMoney->cents(),
                'sender_wallet_id' => null,
                'receiver_wallet_id' => $wallet->id,
                'description' => $description ?: 'Depósito em conta',
            ]);
        });
    }

    /**
     * Executes an atomic peer-to-peer transfer using ordered pessimistic row locks to prevent deadlocks.
     *
     * @throws DomainRuleException
     */
    public function transfer(
        User $sender,
        string $recipientIdentifier,
        int $amountCents,
        ?string $description = null,
    ): Transaction {
        $transferMoney = Money::fromCents($amountCents);
        $recipientUser = $this->resolveRecipientUser($recipientIdentifier);

        return DB::transaction(function () use ($sender, $recipientUser, $transferMoney, $description): Transaction {
            $senderWalletRef = Wallet::where('user_id', $sender->id)->firstOrFail();
            $receiverWalletRef = Wallet::where('user_id', $recipientUser->id)->firstOrFail();

            if ($senderWalletRef->id === $receiverWalletRef->id) {
                throw new DomainRuleException('Não é permitido transferir para a própria carteira.');
            }

            /** @var Collection<int, Wallet> $lockedWallets */
            $lockedWallets = Wallet::whereIn('id', [$senderWalletRef->id, $receiverWalletRef->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Wallet $senderWallet */
            $senderWallet = $lockedWallets->get($senderWalletRef->id);
            /** @var Wallet $receiverWallet */
            $receiverWallet = $lockedWallets->get($receiverWalletRef->id);

            TransactionDomainRules::assertTransferAllowed(
                senderWalletId: $senderWallet->id,
                receiverWalletId: $receiverWallet->id,
                senderBalanceCents: $senderWallet->balance_cents,
                transferAmount: $transferMoney,
            );

            $senderWallet->update([
                'balance_cents' => $senderWallet->balance_cents - $transferMoney->cents(),
            ]);

            $receiverWallet->update([
                'balance_cents' => $receiverWallet->balance_cents + $transferMoney->cents(),
            ]);

            return Transaction::create([
                'type' => TransactionType::TRANSFER->value,
                'status' => TransactionStatus::COMPLETED->value,
                'amount_cents' => $transferMoney->cents(),
                'sender_wallet_id' => $senderWallet->id,
                'receiver_wallet_id' => $receiverWallet->id,
                'description' => $description ?: 'Transferência para '.$recipientUser->name,
            ]);
        });
    }

    /**
     * Reverses a completed deposit or transfer idempotently and records an immutable reversal entry.
     *
     * @throws DomainRuleException
     */
    public function reverseTransaction(User $actor, int $transactionId, ?string $reason = null): Transaction
    {
        return DB::transaction(function () use ($actor, $transactionId, $reason): Transaction {
            $originalTransaction = Transaction::whereKey($transactionId)
                ->lockForUpdate()
                ->first();

            if ($originalTransaction === null) {
                throw new DomainRuleException('Transação não encontrada.', 404);
            }

            $actorWallet = Wallet::where('user_id', $actor->id)->firstOrFail();

            TransactionDomainRules::assertReversalAllowed(
                type: $originalTransaction->type,
                status: $originalTransaction->status,
                actorWalletId: $actorWallet->id,
                senderWalletId: $originalTransaction->sender_wallet_id,
                receiverWalletId: $originalTransaction->receiver_wallet_id,
            );

            $walletIdsToLock = array_values(array_unique(array_filter([
                $originalTransaction->sender_wallet_id,
                $originalTransaction->receiver_wallet_id,
            ])));

            /** @var Collection<int, Wallet> $lockedWallets */
            $lockedWallets = Wallet::whereIn('id', $walletIdsToLock)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Wallet $receiverWallet */
            $receiverWallet = $lockedWallets->get($originalTransaction->receiver_wallet_id);
            $receiverWallet->update([
                'balance_cents' => $receiverWallet->balance_cents - $originalTransaction->amount_cents,
            ]);

            if ($originalTransaction->type === TransactionType::TRANSFER->value && $originalTransaction->sender_wallet_id !== null) {
                /** @var Wallet $senderWallet */
                $senderWallet = $lockedWallets->get($originalTransaction->sender_wallet_id);
                $senderWallet->update([
                    'balance_cents' => $senderWallet->balance_cents + $originalTransaction->amount_cents,
                ]);
            }

            $resolvedReason = trim((string) $reason) !== ''
                ? trim((string) $reason)
                : 'Solicitação de estorno pelo usuário';

            $originalTransaction->update([
                'status' => TransactionStatus::REVERSED->value,
                'reversal_reason' => $resolvedReason,
                'reversed_at' => now(),
            ]);

            return Transaction::create([
                'type' => TransactionType::REVERSAL->value,
                'status' => TransactionStatus::COMPLETED->value,
                'amount_cents' => $originalTransaction->amount_cents,
                'sender_wallet_id' => $originalTransaction->receiver_wallet_id,
                'receiver_wallet_id' => $originalTransaction->sender_wallet_id ?? $originalTransaction->receiver_wallet_id,
                'original_transaction_id' => $originalTransaction->id,
                'description' => 'Estorno da operação #'.$originalTransaction->id,
                'reversal_reason' => $resolvedReason,
            ]);
        });
    }

    /**
     * Finds a recipient user by email address or 11-digit CPF.
     *
     * @throws DomainRuleException
     */
    private function resolveRecipientUser(string $identifier): User
    {
        $cleaned = trim($identifier);
        $digitsOnly = preg_replace('/\D/', '', $cleaned) ?? '';

        $user = User::where('email', strtolower($cleaned))
            ->when(strlen($digitsOnly) === 11, function ($query) use ($digitsOnly): void {
                $query->orWhere('cpf', $digitsOnly);
            })
            ->first();

        if ($user === null) {
            throw new DomainRuleException('Destinatário não encontrado para o CPF ou e-mail informado.', 404);
        }

        return $user;
    }
}

