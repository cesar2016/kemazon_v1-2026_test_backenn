<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\Bid;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AutoBidTest extends TestCase
{
    use RefreshDatabase;

    protected function getHeaders(User $user)
    {
        $token = JWTAuth::fromUser($user);
        return [
            'Authorization' => "Bearer $token",
            'Accept' => 'application/json',
        ];
    }

    public function test_auto_bid_flow()
    {
        // 1. Setup seller and user A, user B
        $seller = User::factory()->create(['is_seller' => true]);
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // 2. Create a product and an auction
        $product = Product::factory()->create(['user_id' => $seller->id]);
        $auction = Auction::factory()->create([
            'product_id' => $product->id,
            'starting_price' => 1000,
            'current_price' => 1000,
            'status' => 'active',
            'is_active' => true,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);

        // 3. User A sets an auto-bid with max 5000
        // First bid will be starting_price + increment (min 10% for < 20000)
        // Wait, current_price is 1000, increment is 100. Next min bid is 1100.
        $responseA = $this->postJson("/api/auctions/{$auction->id}/auto-bid", [
            'max_bid' => 5000
        ], $this->getHeaders($userA));
        $responseA->assertStatus(200);
        $auction->refresh();
        $this->assertEquals(1100, $auction->current_price);
        $this->assertEquals($userA->id, $auction->winner_id);

        // 4. User B places a manual bid of 1500
        // Price should jump to 1500 (User B) then User A's auto-bid should jump to 1500 + increment = 1650
        $responseB = $this->postJson("/api/auctions/{$auction->id}/bid", [
            'amount' => 1500
        ], $this->getHeaders($userB));

        $responseB->assertStatus(200);
        $auction->refresh();
        $this->assertEquals($userA->id, $auction->winner_id);
        $this->assertEquals(1650, $auction->current_price); // 1500 + 150 (10% of 1500)
    }

    public function test_auto_bid_vs_auto_bid_war()
    {
        $seller = User::factory()->create(['is_seller' => true]);
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $product = Product::factory()->create(['user_id' => $seller->id]);
        $auction = Auction::create([
            'product_id' => $product->id,
            'starting_price' => 1000,
            'current_price' => 1000,
            'status' => 'active',
            'is_active' => true,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);

        // User A sets 3000
        $this->postJson("/api/auctions/{$auction->id}/auto-bid", ['max_bid' => 3000], $this->getHeaders($userA));
        // User B sets 5000
        // User A is at 1100. User B sets auto-bid. 
        // User B will jump over User A's max of 3000.
        // B will bid 3000 + increment (10% of 3000 = 300) = 3300.
        $this->postJson("/api/auctions/{$auction->id}/auto-bid", ['max_bid' => 5000], $this->getHeaders($userB));

        $auction->refresh();
        $this->assertEquals($userB->id, $auction->winner_id);
        $this->assertEquals(3146, $auction->current_price);
    }
}
