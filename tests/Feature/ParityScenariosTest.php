<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Business-parity scenarios shared with the Node version (same inputs, same outcome, same HTTP status).
 * Mirror of backend/test/parity.e2e-spec.ts in the Node repository.
 */
class ParityScenariosTest extends TestCase
{
    use RefreshDatabase;

    private int $cpfCounter = 10_000_000_000;

    public function test_rejects_duplicate_email_and_duplicate_cpf_with_409(): void
    {
        $payload = ['name' => 'Gabriel', 'email' => 'dup@test.com', 'cpf' => '12345678901', 'password' => 'password123'];
        $this->postJson('/register', $payload)->assertCreated();
        $this->postJson('/logout');

        $this->postJson('/register', [...$payload, 'cpf' => '10987654321'])->assertStatus(409);
        $this->postJson('/register', [...$payload, 'email' => 'other@test.com'])->assertStatus(409);
    }

    public function test_rejects_zero_negative_and_above_ceiling_deposits_with_422(): void
    {
        $user = $this->userWithBalance('deposit@test.com', 0);

        foreach ([0, -100, 100_000_001] as $amountCents) {
            $this->actingAs($user)->postJson('/wallet/deposit', ['amount_cents' => $amountCents])->assertStatus(422);
        }
    }

    public function test_rejects_transfer_to_yourself_with_422_and_unknown_recipient_with_404(): void
    {
        $user = $this->userWithBalance('self@test.com', 10_000);

        $this->actingAs($user)->postJson('/wallet/transfer', ['recipient' => 'self@test.com', 'amount_cents' => 100])
            ->assertStatus(422);
        $this->actingAs($user)->postJson('/wallet/transfer', ['recipient' => 'nobody@test.com', 'amount_cents' => 100])
            ->assertNotFound();
    }

    public function test_rejects_reusing_an_idempotency_key_for_a_different_operation_with_409(): void
    {
        $user = $this->userWithBalance('reuse@test.com', 0);

        $this->actingAs($user)->withHeader('X-Idempotency-Key', 'reused-key')
            ->postJson('/wallet/deposit', ['amount_cents' => 5_000])->assertCreated();
        $this->actingAs($user)->withHeader('X-Idempotency-Key', 'reused-key')
            ->postJson('/wallet/deposit', ['amount_cents' => 9_999])->assertStatus(409);

        $this->assertSame(5_000, Wallet::where('user_id', $user->id)->value('balance_cents'));
    }

    public function test_enforces_reversal_rules_non_participant_403_outside_window_422_duplicate_409(): void
    {
        $sender = $this->userWithBalance('rev-a@test.com', 10_000);
        $this->userWithBalance('rev-b@test.com', 0);
        $outsider = $this->userWithBalance('rev-c@test.com', 0);

        $id = $this->actingAs($sender)->postJson('/wallet/transfer', ['recipient' => 'rev-b@test.com', 'amount_cents' => 3_000])
            ->assertCreated()->json('transaction.id');

        $this->actingAs($outsider)->postJson("/wallet/transactions/{$id}/reverse")->assertForbidden();
        $this->actingAs($sender)->postJson("/wallet/transactions/{$id}/reverse")->assertOk();
        $this->actingAs($sender)->postJson("/wallet/transactions/{$id}/reverse")->assertStatus(409);

        $lateId = $this->actingAs($sender)->postJson('/wallet/transfer', ['recipient' => 'rev-b@test.com', 'amount_cents' => 1_000])
            ->assertCreated()->json('transaction.id');
        Transaction::whereKey($lateId)->update(['created_at' => now()->subMinutes(16)]);

        $this->actingAs($sender)->postJson("/wallet/transactions/{$lateId}/reverse")->assertStatus(422);
    }

    private function userWithBalance(string $email, int $balanceCents): User
    {
        $user = User::factory()->create(['email' => $email, 'cpf' => (string) $this->cpfCounter++]);
        Wallet::create(['user_id' => $user->id, 'balance_cents' => $balanceCents]);

        return $user;
    }
}
