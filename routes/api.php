<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuctionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductLikeController;
use App\Http\Controllers\Api\ProductVisitController;
use App\Http\Controllers\Api\SeoController;
use Illuminate\Support\Facades\Route;

Route::get('/test', function () {
    return ['status' => 'ok'];
});

Route::get('/og/producto/{slug}', function ($slug) {
    $userAgent = request()->header('User-Agent', '');
    $isCrawler = preg_match('/(facebook|whatsapp|twitter|linkedin|google|bot|crawler|spider)/i', $userAgent);
    
    // Redirect regular users to frontend
    if (!$isCrawler) {
        return redirect('https://kemazon.ar/producto/' . $slug);
    }
    
    $product = \App\Models\Product::where('slug', $slug)->where('is_active', true)->first();
    
    if (!$product) {
        return response()->json(['error' => 'Product not found'], 404);
    }
    
    $name = htmlspecialchars($product->name, ENT_QUOTES, 'UTF-8');
    $description = htmlspecialchars(substr($product->description ?? "Producto en KEMAZON.ar", 0, 160), ENT_QUOTES, 'UTF-8');
    $price = number_format($product->price, 0, ',', '.');
    $imageUrl = config('app.url') . "/api/products/image/{$slug}";
    $frontendUrl = 'https://kemazon.ar';
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
    <script>setTimeout(function(){ window.location.href = "{$pageUrl}"; }, 2000);</script>
</body>
</html>
HTML;
    
    return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
});

Route::get('/og/subasta/{slug}', function ($slug) {
    $userAgent = request()->header('User-Agent', '');
    $isCrawler = preg_match('/(facebook|whatsapp|twitter|linkedin|google|bot|crawler|spider)/i', $userAgent);
    
    // Redirect regular users to frontend
    if (!$isCrawler) {
        return redirect('https://kemazon.ar/subasta/' . $slug);
    }
    
    $product = \App\Models\Product::where('slug', $slug)
        ->where('type', 'auction')
        ->where('is_active', true)
        ->with('auction')
        ->first();
    
    if (!$product) {
        return response()->json(['error' => 'Auction not found'], 404);
    }
    
    $name = htmlspecialchars($product->name, ENT_QUOTES, 'UTF-8');
    $description = htmlspecialchars(substr($product->description ?? "Subasta en KEMAZON.ar", 0, 160), ENT_QUOTES, 'UTF-8');
    $price = number_format($product->auction?->current_price ?? $product->price ?? 0, 0, ',', '.');
    $imageUrl = config('app.url') . "/api/products/image/{$slug}";
    $frontendUrl = 'https://kemazon.ar';
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
    <script>setTimeout(function(){ window.location.href = "{$pageUrl}"; }, 2000);</script>
</body>
</html>
HTML;
    
    return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
});

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth.api')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::post('/avatar', [AuthController::class, 'updateAvatar']);
        Route::post('/become-seller', [AuthController::class, 'becomeSeller']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });
});

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{slug}', [CategoryController::class, 'show']);

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}/image', [ProductController::class, 'getImage']);
Route::get('/products/{id}/thumbnail', [ProductController::class, 'getImage']);
Route::get('/products/image/{slug}', [ProductController::class, 'getImageBySlug']);
Route::get('/products/{slug}', [ProductController::class, 'show']);
Route::get('/products/search/{query}', [ProductController::class, 'index']);
Route::post('/products/{id}/like', [ProductLikeController::class, 'toggle']);
Route::get('/products/{id}/likers', [ProductLikeController::class, 'index']);

Route::post('/products/{id}/visit/ping', [ProductVisitController::class, 'ping']);
Route::get('/products/{id}/visitors', [ProductVisitController::class, 'index']);

Route::get('/auctions', [AuctionController::class, 'index']);
Route::get('/auctions/{slug}', [AuctionController::class, 'show']);

Route::middleware('auth.api')->group(function () {
    Route::get('/cart', [CartController::class, 'index']);
    Route::post('/cart', [CartController::class, 'store']);
    Route::put('/cart/{id}', [CartController::class, 'update']);
    Route::delete('/cart/{id}', [CartController::class, 'destroy']);
    Route::delete('/cart', [CartController::class, 'clear']);
    Route::post('/cart/checkout', [CartController::class, 'checkout']);

    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
    Route::put('/orders/{id}/status', [OrderController::class, 'updateStatus']);
    Route::put('/orders/{id}/payment', [OrderController::class, 'updatePaymentStatus']);

    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::get('/addresses/{id}', [AddressController::class, 'show']);
    Route::put('/addresses/{id}', [AddressController::class, 'update']);
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy']);
    Route::post('/addresses/{id}/default', [AddressController::class, 'setDefault']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread', [NotificationController::class, 'unread']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::delete('/notifications', [NotificationController::class, 'clear']);

    Route::get('/dashboard/stats', [DashboardController::class, 'sellerStats']);
    Route::get('/dashboard/activity', [DashboardController::class, 'recentActivity']);
    Route::get('/dashboard/sales-report', [DashboardController::class, 'salesReport']);
    Route::get('/dashboard/admin-stats', [DashboardController::class, 'adminStats']);

    Route::get('/seller/products', [ProductController::class, 'myProducts']);
    Route::get('/seller/products/{id}', [ProductController::class, 'showById']);
    Route::post('/seller/products', [ProductController::class, 'store']);
    Route::put('/seller/products/{id}', [ProductController::class, 'update']);
    Route::delete('/seller/products/{id}', [ProductController::class, 'destroy']);

    Route::get('/seller/auctions', [AuctionController::class, 'myAuctions']);
    Route::post('/seller/auctions', [AuctionController::class, 'store']);
    Route::put('/seller/auctions/{id}', [AuctionController::class, 'update']);
    Route::post('/seller/auctions/{id}/end', [AuctionController::class, 'endAuction']);

    Route::get('/my-bids', [AuctionController::class, 'myBids']);
    Route::post('/auctions/{id}/bid', [AuctionController::class, 'placeBid']);
    Route::post('/auctions/{id}/auto-bid', [AuctionController::class, 'configureAutoBid']);
    Route::post('/auctions/{id}/buy-now', [AuctionController::class, 'buyNow']);

    Route::get('/seller/orders', [OrderController::class, 'sellerOrders']);
});

Route::get('/seo/product/{slug}', [SeoController::class, 'product']);
Route::get('/seo/auction/{slug}', [SeoController::class, 'auction']);

Route::get('/download/prerendered', function () {
    $prerenderedDir = storage_path('app/public/prerendered');
    
    if (!file_exists($prerenderedDir)) {
        return response()->json(['error' => 'No hay archivos pre-renderizados'], 404);
    }
    
    $files = glob("{$prerenderedDir}/*.html");
    
    if (empty($files)) {
        return response()->json(['error' => 'No hay archivos pre-renderizados'], 404);
    }
    
    $zipFile = storage_path('app/public/prerendered.zip');
    
    if (file_exists($zipFile)) {
        unlink($zipFile);
    }
    
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
        return response()->json(['error' => 'No se pudo crear el ZIP'], 500);
    }
    
    foreach ($files as $file) {
        $filename = basename($file);
        $zip->addFile($file, $filename);
    }
    
    $zip->close();
    
    return response()->download($zipFile, 'prerendered-pages.zip')->deleteFileAfterSend(true);
});

Route::middleware(['auth.api', 'admin'])->group(function () {
    Route::get('/admin/users', [AdminController::class, 'index']);
    Route::post('/admin/users/{id}/toggle-status', [AdminController::class, 'toggleStatus']);
    Route::put('/admin/users/{id}/role', [AdminController::class, 'updateRole']);
    Route::post('/admin/notifications/broadcast', [AdminController::class, 'broadcastNotification']);

    Route::post('/admin/categories', [CategoryController::class, 'store']);
    Route::put('/admin/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/admin/categories/{id}', [CategoryController::class, 'destroy']);
});