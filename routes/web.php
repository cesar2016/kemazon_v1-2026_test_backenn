<?php

use Illuminate\Support\Facades\Route;
use App\Models\Product;

Route::get('/', function () {
    return redirect('https://kemazon.ar');
});

Route::get('/producto/{slug}', function ($slug) {
    $userAgent = request()->header('User-Agent', '');
    $isCrawler = preg_match('/(facebook|whatsapp|twitter|linkedin|google|bot|crawler|spider)/i', $userAgent);
    
    $product = Product::where('slug', $slug)->where('is_active', true)->first();
    
    if (!$product && !$isCrawler) {
        abort(404);
    }
    
    if ($product && $isCrawler) {
        $name = htmlspecialchars($product->name, ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars(substr($product->description ?? "Producto en KEMAZON.ar", 0, 160), ENT_QUOTES, 'UTF-8');
        $price = number_format($product->price, 0, ',', '.');
        $imageUrl = config('app.url') . "/api/products/image/{$slug}";
        $frontendUrl = config('app.frontend_url', 'https://kemazon.ar');
        $pageUrl = $frontendUrl . "/producto/{$slug}";
        
        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$name} | KEMAZON.ar</title>
    <meta name="description" content="{$description}">
    
    <meta property="og:type" content="product">
    <meta property="og:title" content="{$name}">
    <meta property="og:description" content="{$description}">
    <meta property="og:image" content="{$imageUrl}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:url" content="{$pageUrl}">
    <meta property="og:site_name" content="KEMAZON.ar">
    <meta property="product:price:amount" content="{$product->price}">
    <meta property="product:price:currency" content="ARS">
    
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{$name}">
    <meta name="twitter:description" content="{$description}">
    <meta name="twitter:image" content="{$imageUrl}">
</head>
<body>
    <h1>{$name}</h1>
    <p>💰 {$price}</p>
    <p>Redireccionando a <a href="{$frontendUrl}/producto/{$slug}">KEMAZON.ar</a>...</p>
</body>
</html>
HTML;
        
        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
    
    $filePath = storage_path("app/public/prerendered/producto-{$slug}.html");
    if (file_exists($filePath)) {
        return response(file_get_contents($filePath))
            ->header('Content-Type', 'text/html');
    }
    
    return redirect('https://kemazon.ar/producto/' . $slug);
});

Route::get('/subasta/{slug}', function ($slug) {
    $userAgent = request()->header('User-Agent', '');
    $isCrawler = preg_match('/(facebook|whatsapp|twitter|linkedin|google|bot|crawler|spider)/i', $userAgent);
    
    $product = Product::where('slug', $slug)
        ->where('type', 'auction')
        ->where('is_active', true)
        ->with('auction')
        ->first();
    
    if (!$product && !$isCrawler) {
        abort(404);
    }
    
    if ($product && $isCrawler) {
        $name = htmlspecialchars($product->name, ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars(substr($product->description ?? "Subasta en KEMAZON.ar", 0, 160), ENT_QUOTES, 'UTF-8');
        $price = number_format($product->auction?->current_price ?? $product->price ?? 0, 0, ',', '.');
        $imageUrl = config('app.url') . "/api/products/image/{$slug}";
        $frontendUrl = config('app.frontend_url', 'https://kemazon.ar');
        $pageUrl = $frontendUrl . "/subasta/{$slug}";
        
        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$name} | Subasta KEMAZON.ar</title>
    <meta name="description" content="{$description}">
    
    <meta property="og:type" content="product">
    <meta property="og:title" content="{$name} - Subasta">
    <meta property="og:description" content="{$description}">
    <meta property="og:image" content="{$imageUrl}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:url" content="{$pageUrl}">
    <meta property="og:site_name" content="KEMAZON.ar">
    
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{$name} - Subasta">
    <meta name="twitter:description" content="{$description}">
    <meta name="twitter:image" content="{$imageUrl}">
</head>
<body>
    <h1>{$name}</h1>
    <p>🏷️ Subasta: {$price}</p>
    <p>Redireccionando a <a href="{$frontendUrl}/subasta/{$slug}">KEMAZON.ar</a>...</p>
</body>
</html>
HTML;
        
        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
    
    $filePath = storage_path("app/public/prerendered/subasta-{$slug}.html");
    if (file_exists($filePath)) {
        return response(file_get_contents($filePath))
            ->header('Content-Type', 'text/html');
    }
    
    return redirect('https://kemazon.ar/subasta/' . $slug);
});