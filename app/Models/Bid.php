<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bid extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'auction_id',
        'user_id',
        'amount',
        'max_bid',
        'is_auto_bid',
        'is_winning',
        'ip_address',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'max_bid' => 'decimal:2',
        'is_auto_bid' => 'boolean',
        'is_winning' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::creating(function ($bid) {
            $bid->created_at = now();
            $bid->updated_at = now();
        });
    }
}
