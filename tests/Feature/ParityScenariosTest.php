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

    public function test_another_users_idempotency_key_returns_409_without_exposing_the_transaction(): void
    {
        $alice = $this->userWithBalance('alice@test.com', 0);
        $bob = $this->userWithBalance('bob@test.com', 0);

        $this->actingAs($alice)->withHeader('X-Idempotency-Key', 'alice-key')
            ->postJson('/wallet/deposit', ['amount_cents' => 5_000])->assertCreated();

        $response = $this->actingAs($bob)->withHeader('X-Idempotency-Key', 'alice-key')
            ->postJson('/wallet/deposit', ['amount_cents' => 5_000]);

        $response->assertStatus(409)->assertJsonMissingPath('transaction');
        $this->assertSame(0, Wallet::where('user_id', $bob->id)->value('balance_cents'));
    }

    public function test_amounts_typed_in_brl_above_the_ceiling_report_the_limit_not_zero(): void
    {
        $user = $this->userWithBalance('ceiling@test.com', 0);
        $limitMessage = 'O valor excede o limite máximo permitido por operação.';

        $this->actingAs($user)->postJson('/wallet/deposit', ['amount' => '555.555.555,55'])
            ->assertStatus(422)->assertJsonPath('message', $limitMessage);
        $this->actingAs($user)->postJson('/wallet/deposit', ['amount' => '1.000.000,01'])
            ->assertStatus(422)->assertJsonPath('message', $limitMessage);
        $this->actingAs($user)->postJson('/wallet/transfer', ['recipient' => 'x@test.com', 'amount' => '555.555.555,55'])
            ->assertStatus(422)->assertJsonPath('message', $limitMessage);
        $this->actingAs($user)->postJson('/wallet/deposit', ['amount' => 'abc'])
            ->assertStatus(422)->assertJsonPath('message', 'O valor do depósito é inválido.');
        $this->actingAs($user)->postJson('/wallet/deposit', ['amount' => '1.000.000,00'])
            ->assertCreated();
    }

    public function test_form_error_feedback_is_shown_only_in_the_tab_it_came_from(): void
    {
        $user = $this->userWithBalance('feedback@test.com', 0);

        $html = $this->actingAs($user)
            ->followingRedirects()
            ->from('/dashboard?tab=deposit')
            ->post('/wallet/deposit', ['amount' => '555.555.555,55'])
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count((string) $html, 'O valor excede o limite máximo permitido por operação.'));
        $this->assertStringNotContainsString('deve ser maior que zero', (string) $html);
    }

    public function test_rejects_non_integer_json_amounts_such_as_booleans_and_numeric_strings(): void
    {
        $user = $this->userWithBalance('types@test.com', 0);

        foreach ([true, '100', 10.5, null] as $amount) {
            $this->actingAs($user)->postJson('/wallet/deposit', ['amount_cents' => $amount])->assertStatus(422);
        }

        $this->assertSame(0, Wallet::where('user_id', $user->id)->value('balance_cents'));
    }

    public function test_reverse_with_a_non_numeric_id_is_404_not_500(): void
    {
        $user = $this->userWithBalance('badid@test.com', 0);

        $this->actingAs($user)->postJson('/wallet/transactions/abc/reverse')->assertNotFound();
    }

    public function test_outsider_gets_403_even_for_an_already_reversed_transaction(): void
    {
        $sender = $this->userWithBalance('own-a@test.com', 10_000);
        $this->userWithBalance('own-b@test.com', 0);
        $outsider = $this->userWithBalance('own-c@test.com', 0);

        $id = $this->actingAs($sender)->postJson('/wallet/transfer', ['recipient' => 'own-b@test.com', 'amount_cents' => 1_000])
            ->assertCreated()->json('transaction.id');
        $this->actingAs($sender)->postJson("/wallet/transactions/{$id}/reverse")->assertOk();

        $this->actingAs($outsider)->postJson("/wallet/transactions/{$id}/reverse")->assertForbidden();
    }

    private function userWithBalance(string $email, int $balanceCents): User
    {
        $user = User::factory()->create(['email' => $email, 'cpf' => (string) $this->cpfCounter++]);
        Wallet::create(['user_id' => $user->id, 'balance_cents' => $balanceCents]);

        return $user;
    }
}
