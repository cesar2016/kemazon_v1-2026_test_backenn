<?php
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Models\User::where('is_seller', true)->first();
auth()->login($user);

$auction = \App\Models\Auction::first();
echo "Auction ID: " . $auction->id . "\n";
echo "Current ends_at: " . $auction->ends_at . "\n";
echo "Bids count: " . $auction->bids()->count() . "\n";

$request = \Illuminate\Http\Request::create('/api/seller/auctions/' . $auction->id, 'PUT', [
    'starts_at' => $auction->starts_at,
    'ends_at'   => $auction->ends_at,
    'starting_price' => 500
]);

$controller = new \App\Http\Controllers\Api\AuctionController();
$response = $controller->update($request, $auction->id);
echo "Response: " . json_encode($response->getData(), JSON_PRETTY_PRINT)."\n";
