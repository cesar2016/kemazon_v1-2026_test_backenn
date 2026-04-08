<?php

namespace Database\Factories;

use App\Models\Bid;
use App\Models\Auction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BidFactory extends Factory
{
    protected $model = Bid::class;

    public function definition(): array
    {
        return [
            'auction_id' => Auction::factory(),
            'user_id' => User::factory(),
            'amount' => $this->faker->randomFloat(2, 1000, 5000),
            'max_bid' => null,
            'is_auto_bid' => false,
            'is_winning' => false,
            'ip_address' => $this->faker->ipv4(),
        ];
    }
}
