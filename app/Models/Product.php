<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'name',
        'slug',
        'description',
        'price',
        'stock',
        'sku',
        'images',
        'thumbnail',
        'is_active',
        'type',
        'specifications',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
        'images' => 'array',
        'is_active' => 'boolean',
        'specifications' => 'array',
    ];

    private static function currentRequestOrigin(): ?string
    {
        if (!request()) {
            return null;
        }

        $forwardedProto = request()->headers->get('x-forwarded-proto');
        $scheme = $forwardedProto ? trim(explode(',', $forwardedProto)[0]) : request()->getScheme();
        $host = request()->headers->get('x-forwarded-host')
            ? trim(explode(',', request()->headers->get('x-forwarded-host'))[0])
            : request()->getHost();
        $port = request()->headers->get('x-forwarded-port') ?: request()->getPort();

        if (!$host) {
            return null;
        }

        $origin = $scheme . '://' . $host;
        $isStandardPort = ($scheme === 'http' && (int) $port === 80)
            || ($scheme === 'https' && (int) $port === 443);

        if ($port && !$isStandardPort && !str_contains($host, ':')) {
            $origin .= ':' . $port;
        }

        return $origin;
    }

    private static function normalizeMediaUrl(?string $value): ?string
    {
        if (!$value) {
            return $value;
        }

        if (str_starts_with($value, 'data:image/')) {
            return $value;
        }

        $origin = self::currentRequestOrigin();
        if (!$origin) {
            return $value;
        }

        if (str_starts_with($value, '/')) {
            return rtrim($origin, '/') . $value;
        }

        if (!preg_match('/^https?:\/\//i', $value)) {
            return $value;
        }

        $mediaHost = parse_url($value, PHP_URL_HOST);
        $mediaPort = parse_url($value, PHP_URL_PORT);
        $requestHost = parse_url($origin, PHP_URL_HOST);
        $requestPort = parse_url($origin, PHP_URL_PORT);
        $requestScheme = parse_url($origin, PHP_URL_SCHEME);

        if ($mediaHost && $requestHost && $mediaHost === $requestHost && (string) ($mediaPort ?? '') === (string) ($requestPort ?? '')) {
            $updated = preg_replace('/^https?:\/\//i', $requestScheme . '://', $value);
            return $updated ?: $value;
        }

        return $value;
    }

    public function getThumbnailAttribute($value): ?string
    {
        return self::normalizeMediaUrl($value);
    }

    public function getImagesAttribute($value): array
    {
        $decoded = is_array($value) ? $value : (json_decode($value ?? '[]', true) ?: []);

        return array_map(
            fn ($image) => is_string($image) ? self::normalizeMediaUrl($image) : $image,
            $decoded
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function auction(): HasOne
    {
        return $this->hasOne(Auction::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function bids(): HasManyThrough
    {
        return $this->hasManyThrough(Bid::class, Auction::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(ProductLike::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(ProductVisit::class);
    }

    public function getLikesCountAttribute(): int
    {
        return $this->likes()->count();
    }

    public function getValidVisitsCountAttribute(): int
    {
        return $this->visits()->valid()->count();
    }

    public function isLikedByUser(?int $userId, string $ip): bool
    {
        return $this->likes()
            ->where(function ($query) use ($userId, $ip) {
                if ($userId) {
                    $query->where('user_id', $userId);
                } else {
                    $query->where('user_id', null)
                        ->where('ip_address', $ip);
                }
            })
            ->exists();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDirect($query)
    {
        return $query->where('type', 'direct');
    }

    public function scopeAuction($query)
    {
        return $query->where('type', 'auction');
    }
}
