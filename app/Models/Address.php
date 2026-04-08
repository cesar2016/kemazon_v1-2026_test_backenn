<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'label',
        'recipient_name',
        'phone',
        'address',
        'number',
        'floor',
        'apartment',
        'city',
        'state',
        'postal_code',
        'observations',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getFullAddressAttribute(): string
    {
        $parts = [$this->address, $this->number];
        if ($this->floor) {
            $parts[] = 'Piso ' . $this->floor;
        }
        if ($this->apartment) {
            $parts[] = 'Dto. ' . $this->apartment;
        }
        $parts[] = $this->city . ', ' . $this->state;
        $parts[] = 'CP: ' . $this->postal_code;
        return implode(', ', $parts);
    }
}
