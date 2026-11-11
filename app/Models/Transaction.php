<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Transaction extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'status',
        'amount_cents',
        'sender_wallet_id',
        'receiver_wallet_id',
        'original_transaction_id',
        'description',
        'reversal_reason',
        'reversed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function senderWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'sender_wallet_id');
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function receiverWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'receiver_wallet_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function originalTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_transaction_id');
    }

    /**
     * @return HasOne<self, $this>
     */
    public function reversalTransaction(): HasOne
    {
        return $this->hasOne(self::class, 'original_transaction_id');
    }
}
