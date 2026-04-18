<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class ProductVisit extends Model
{
    use HasFactory;

    protected static ?bool $hasIsValidColumn = null;

    protected $fillable = [
        'product_id',
        'user_id',
        'ip_address',
        'session_id',
        'duration',
        'is_valid',
        'last_active_at',
    ];

    protected $casts = [
        'is_valid' => 'boolean',
        'last_active_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hasIsValidColumn(): bool
    {
        if (static::$hasIsValidColumn === null) {
            static::$hasIsValidColumn = Schema::hasColumn('product_visits', 'is_valid');
        }

        return static::$hasIsValidColumn;
    }

    public function scopeValid(Builder $query): Builder
    {
        if (static::hasIsValidColumn()) {
            return $query->where('is_valid', true);
        }

        return $query->where('duration', '>=', 5);
    }
}
