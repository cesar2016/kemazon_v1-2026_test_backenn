<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'phone',
        'document_type',
        'document_number',
        'is_seller',
        'is_admin',
        'is_blocked',
        'role_id',
        'mercadopago_access_token',
        'mercadopago_public_key',
    ];

    protected $appends = [
        'unread_notifications_count',
        'has_mercadopago_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'mercadopago_access_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_seller' => 'boolean',
            'is_admin' => 'boolean',
            'is_blocked' => 'boolean',
            'role_id' => 'integer',
            'mercadopago_access_token' => 'encrypted',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->is_admin || $this->role_id === 1;
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'is_seller' => $this->is_seller,
            'is_admin' => $this->is_admin,
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function auctions(): HasMany
    {
        return $this->hasMany(Auction::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function wonAuctions(): HasMany
    {
        return $this->hasMany(Auction::class, 'winner_id');
    }

    public function productLikes(): HasMany
    {
        return $this->hasMany(ProductLike::class);
    }

    public function getUnreadNotificationsCountAttribute(): int
    {
        return $this->notifications()->where('is_read', false)->count();
    }

    public function getHasMercadopagoTokenAttribute(): bool
    {
        return !empty($this->mercadopago_access_token);
    }
}
