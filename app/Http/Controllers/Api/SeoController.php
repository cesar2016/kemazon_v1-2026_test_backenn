<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\Auction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeoController extends Controller
{
    public function product(string $slug): JsonResponse
    {
        $product = Product::with('category')->where('slug', $slug)->first();

        if (!$product) {
            return response()->json(['error' => 'Producto no encontrado'], 404);
        }

        $images = $product->images ?? [];
        $thumbnail = $product->thumbnail ?? ($images[0] ?? null);
        if ($thumbnail && !str_starts_with($thumbnail, 'http')) {
            $thumbnail = rtrim(config('app.frontend_url', config('app.url')), '/') . $thumbnail;
        }
        $price = number_format($product->price, 0, ',', '.');
        $baseUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
        $url = "{$baseUrl}/products/{$slug}";

        $meta = [
            'title' => "{$product->name} | KEMAZON.ar",
            'description' => substr($product->description ?? "Compra {$product->name} en KEMAZON.ar - La mejor plataforma de subastas de Argentina.", 0, 160),
            'image' => $thumbnail,
            'url' => $url,
            'type' => 'product',
            'price' => $price,
            'currency' => 'ARS',
        ];

        return response()->json($meta);
    }

    public function auction(string $slug): JsonResponse
    {
        $product = Product::with('auction')->where('slug', $slug)->first();

        if (!$product || !$product->auction) {
            return response()->json(['error' => 'Subasta no encontrada'], 404);
        }

        $auction = $product->auction;
        $images = $product->images ?? [];
        $thumbnail = $product->thumbnail ?? ($images[0] ?? null);
        if ($thumbnail && !str_starts_with($thumbnail, 'http')) {
            $thumbnail = rtrim(config('app.frontend_url', config('app.url')), '/') . $thumbnail;
        }
        $currentPrice = number_format($auction->current_price ?? $auction->starting_price ?? 0, 0, ',', '.');
        $baseUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
        $url = "{$baseUrl}/auctions/{$slug}";

        $meta = [
            'title' => "{$product->name} | Subasta KEMAZON.ar",
            'description' => substr($product->description ?? "Participa en la subasta de {$product->name} en KEMAZON.ar", 0, 160),
            'image' => $thumbnail,
            'url' => $url,
            'type' => 'product',
            'price' => $currentPrice,
            'currency' => 'ARS',
        ];

        return response()->json($meta);
    }
}