<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Wallet\TransactionStatus;
use App\Domain\Wallet\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for wallet deposits, negative balance offset, P2P transfers, reversals, and healthcheck.
 */
class WalletOperationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verifies that an authenticated user can deposit funds using BRL string formatting.
     */
    public function test_authenticated_user_can_deposit_money_into_wallet(): void
    {
        [$user, $wallet] = $this->createUserWithWallet(balanceCents: 10000);

        $response = $this->actingAs($user)->postJson('/wallet/deposit', [
            'amount' => '250,50',
            'description' => 'Aporte inicial',
        ]);

        $response->assertCreated()
            ->assertJsonPath('transaction.type', TransactionType::DEPOSIT->value)
            ->assertJsonPath('transaction.amount_cents', 25050);

        $this->assertSame(35050, $wallet->fresh()?->balance_cents);
    }

    /**
     * Verifies the business requirement that depositing into a negative wallet offsets the debt first.
     */
    public function test_deposit_into_negative_wallet_offsets_debt_and_adds_residual_balance(): void
    {
        [$mariana, $wallet] = $this->createUserWithWallet(balanceCents: -25000);

        $response = $this->actingAs($mariana)->postJson('/wallet/deposit', [
            'amount' => '400,00',
            'description' => 'Cobertura de saldo negativo',
        ]);

        $response->assertCreated();
        $this->assertSame(15000, $wallet->fresh()?->balance_cents);
    }

    /**
     * Verifies peer-to-peer transfers by both email address and formatted CPF.
     */
    public function test_user_can_transfer_funds_by_email_and_by_cpf(): void
    {
        [$sender, $senderWallet] = $this->createUserWithWallet(
            balanceCents: 100000,
            email: 'gabriel@cobuccio.com',
            cpf: '11122233344',
        );

        [$receiver, $receiverWallet] = $this->createUserWithWallet(
            balanceCents: 5000,
            email: 'bruno@cobuccio.com',
            cpf: '55566677788',
        );

        $this->actingAs($sender)->postJson('/wallet/transfer', [
            'recipient' => 'bruno@cobuccio.com',
            'amount' => '300,00',
            'description' => 'Pagamento por e-mail',
        ])->assertCreated();

        $this->actingAs($sender)->postJson('/wallet/transfer', [
            'recipient' => '555.666.777-88',
            'amount' => '200,00',
            'description' => 'Pagamento por CPF',
        ])->assertCreated();

        $this->assertSame(50000, $senderWallet->fresh()?->balance_cents);
        $this->assertSame(55000, $receiverWallet->fresh()?->balance_cents);
    }

    /**
     * Verifies that transfers exceeding the available balance are rejected without altering balances.
     */
    public function test_transfer_fails_when_sender_has_insufficient_balance(): void
    {
        [$sender, $senderWallet] = $this->createUserWithWallet(balanceCents: 10000);
        [$receiver, $receiverWallet] = $this->createUserWithWallet(
            balanceCents: 0,
            email: 'destino@cobuccio.com',
        );

        $response = $this->actingAs($sender)->postJson('/wallet/transfer', [
            'recipient' => 'destino@cobuccio.com',
            'amount' => '150,00',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Saldo insuficiente para realizar a transferência.');

        $this->assertSame(10000, $senderWallet->fresh()?->balance_cents);
        $this->assertSame(0, $receiverWallet->fresh()?->balance_cents);
    }

    /**
     * Verifies that reversing a transfer restores the sender's balance and leaves the receiver negative if funds were spent.
     */
    public function test_transfer_reversal_restores_sender_balance_and_allows_receiver_to_go_negative(): void
    {
        [$sender, $senderWallet] = $this->createUserWithWallet(balanceCents: 50000);
        [$receiver, $receiverWallet] = $this->createUserWithWallet(
            balanceCents: 0,
            email: 'mariana@cobuccio.com',
        );

        [$thirdParty] = $this->createUserWithWallet(
            balanceCents: 0,
            email: 'fornecedor@cobuccio.com',
        );

        $transferResponse = $this->actingAs($sender)->postJson('/wallet/transfer', [
            'recipient' => 'mariana@cobuccio.com',
            'amount' => '400,00',
        ])->assertCreated();

        $transactionId = (int) $transferResponse->json('transaction.id');

        $this->actingAs($receiver)->postJson('/wallet/transfer', [
            'recipient' => 'fornecedor@cobuccio.com',
            'amount' => '250,00',
        ])->assertCreated();

        $reversalResponse = $this->actingAs($sender)->postJson("/wallet/transactions/{$transactionId}/reverse", [
            'reason' => 'Solicitação de estorno por erro de digitação',
        ]);

        $reversalResponse->assertOk()
            ->assertJsonPath('reversal.type', TransactionType::REVERSAL->value)
            ->assertJsonPath('reversal.original_transaction_id', $transactionId);

        $this->assertSame(50000, $senderWallet->fresh()?->balance_cents);
        $this->assertSame(-25000, $receiverWallet->fresh()?->balance_cents);

        $originalTx = Transaction::findOrFail($transactionId);
        $this->assertSame(TransactionStatus::REVERSED->value, $originalTx->status);
        $this->assertNotNull($originalTx->reversed_at);
    }

    /**
     * Verifies that the sender cannot reverse a transfer after the 15-minute window expires.
     */
    public function test_sender_cannot_reverse_transfer_after_fifteen_minute_window(): void
    {
        [$sender, $senderWallet] = $this->createUserWithWallet(balanceCents: 50000);
        [$receiver] = $this->createUserWithWallet(balanceCents: 0, email: 'bruno@cobuccio.com');

        $transferResponse = $this->actingAs($sender)->postJson('/wallet/transfer', [
            'recipient' => 'bruno@cobuccio.com',
            'amount' => '100,00',
        ])->assertCreated();

        $transactionId = (int) $transferResponse->json('transaction.id');

        $this->travel(16)->minutes();

        $response = $this->actingAs($sender)->postJson("/wallet/transactions/{$transactionId}/reverse");

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'O prazo de arrependimento do remetente (15 minutos) expirou.');
    }

    /**
     * Verifies that the receiver can voluntarily return funds within the 24-hour window.
     */
    public function test_receiver_can_return_transfer_within_twenty_four_hours(): void
    {
        [$sender, $senderWallet] = $this->createUserWithWallet(balanceCents: 50000);
        [$receiver, $receiverWallet] = $this->createUserWithWallet(balanceCents: 0, email: 'bruno@cobuccio.com');

        $transferResponse = $this->actingAs($sender)->postJson('/wallet/transfer', [
            'recipient' => 'bruno@cobuccio.com',
            'amount' => '100,00',
        ])->assertCreated();

        $transactionId = (int) $transferResponse->json('transaction.id');

        $this->travel(2)->hours();

        $response = $this->actingAs($receiver)->postJson("/wallet/transactions/{$transactionId}/reverse", [
            'reason' => 'Devolução voluntária pelo recebedor',
        ]);

        $response->assertOk()
            ->assertJsonPath('reversal.type', TransactionType::REVERSAL->value)
            ->assertJsonPath('reversal.original_transaction_id', $transactionId);

        $this->assertSame(50000, $senderWallet->fresh()?->balance_cents);
        $this->assertSame(0, $receiverWallet->fresh()?->balance_cents);
    }

    /**
     * Verifies that a deposit can be reversed and that attempting a second reversal is blocked.
     */
    public function test_deposit_reversal_is_idempotent_and_blocks_duplicate_reversals(): void
    {
        [$user, $wallet] = $this->createUserWithWallet(balanceCents: 0);

        $depositResponse = $this->actingAs($user)->postJson('/wallet/deposit', [
            'amount' => '500,00',
        ])->assertCreated();

        $depositId = (int) $depositResponse->json('transaction.id');
        $this->assertSame(50000, $wallet->fresh()?->balance_cents);

        $this->actingAs($user)
            ->postJson("/wallet/transactions/{$depositId}/reverse", [
                'reason' => 'Depósito duplicado',
            ])
            ->assertOk();

        $this->assertSame(0, $wallet->fresh()?->balance_cents);

        $secondAttempt = $this->actingAs($user)
            ->postJson("/wallet/transactions/{$depositId}/reverse");

        $secondAttempt->assertUnprocessable()
            ->assertJsonPath('message', 'Esta operação já foi revertida anteriormente.');
    }

    /**
     * Verifies the database healthcheck endpoint.
     */
    public function test_healthcheck_endpoint_verifies_database_connection(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', 'connected');
    }

    /**
     * Verifies that the dashboard Blade view renders with modules, formatted balance, and negative balance warning.
     */
    public function test_dashboard_view_renders_modules_and_negative_balance_warning(): void
    {
        [$mariana] = $this->createUserWithWallet(
            balanceCents: -25000,
            email: 'mariana@cobuccio.com',
        );

        $this->actingAs($mariana)
            ->get('/dashboard?tab=deposit')
            ->assertOk()
            ->assertSee('Alerta de Saldo Devedor (-R$ 250,00)')
            ->assertSee('Simulador Contábil de Depósito')
            ->assertSee('Extrato Contábil (Ledger) & Reversões', false);
    }

    /**
     * Helper to provision a user and an associated wallet for testing.
     *
     * @return array{0: User, 1: Wallet}
     */
    private function createUserWithWallet(
        int $balanceCents,
        ?string $email = null,
        ?string $cpf = null,
    ): array {
        $attributes = [];
        if ($email !== null) {
            $attributes['email'] = $email;
        }
        if ($cpf !== null) {
            $attributes['cpf'] = $cpf;
        }

        $user = User::factory()->create($attributes);
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'balance_cents' => $balanceCents,
        ]);

        return [$user, $wallet];
    }
}
