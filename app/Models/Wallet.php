<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'balance_cents',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function sentTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'sender_wallet_id');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function receivedTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'receiver_wallet_id');
    }
}
