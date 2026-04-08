<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Auction extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'starting_price',
        'current_price',
        'reserve_price',
        'buy_now_price',
        'starts_at',
        'ends_at',
        'is_active',
        'has_reserve',
        'winner_id',
        'status',
    ];

    protected $casts = [
        'starting_price' => 'decimal:2',
        'current_price' => 'decimal:2',
        'reserve_price' => 'decimal:2',
        'buy_now_price' => 'decimal:2',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
        'has_reserve' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function winningBid(): HasOne
    {
        return $this->hasOne(Bid::class)->where('is_winning', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('status', 'active')
            ->where('ends_at', '>', now());
    }

    public function scopeEnded($query)
    {
        return $query->where('ends_at', '<=', now());
    }

    public function isEnded(): bool
    {
        return $this->ends_at->isPast();
    }

    public function reserveMet(): bool
    {
        if (!$this->has_reserve || !$this->reserve_price) {
            return true;
        }
        return $this->current_price >= $this->reserve_price;
    }
}
