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

Route::middleware(['auth.api', 'admin'])->group(function () {
    Route::get('/admin/users', [AdminController::class, 'index']);
    Route::post('/admin/users/{id}/toggle-status', [AdminController::class, 'toggleStatus']);
    Route::put('/admin/users/{id}/role', [AdminController::class, 'updateRole']);
    Route::post('/admin/notifications/broadcast', [AdminController::class, 'broadcastNotification']);

    Route::post('/admin/categories', [CategoryController::class, 'store']);
    Route::put('/admin/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/admin/categories/{id}', [CategoryController::class, 'destroy']);
});