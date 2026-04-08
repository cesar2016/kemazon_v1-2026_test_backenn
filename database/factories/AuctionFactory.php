<?php

namespace Database\Factories;

use App\Models\Auction;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuctionFactory extends Factory
{
    protected $model = Auction::class;

    public function definition(): array
    {
        $startingPrice = $this->faker->randomFloat(2, 100, 1000);
        return [
            'product_id' => Product::factory(),
            'starting_price' => $startingPrice,
            'current_price' => $startingPrice,
            'reserve_price' => $startingPrice * 1.5,
            'buy_now_price' => $startingPrice * 3,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'status' => 'active',
            'is_active' => true,
        ];
    }
}
