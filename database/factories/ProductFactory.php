<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = $this->faker->sentence(3);
        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $this->faker->paragraph(),
            'price' => $this->faker->randomFloat(2, 10, 1000),
            'stock' => $this->faker->numberBetween(1, 50),
            'sku' => $this->faker->unique()->bothify('SKU-####-????'),
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'thumbnail' => 'https://via.placeholder.com/400x400?text=Product',
            'images' => ['https://via.placeholder.com/400x400?text=Image1'],
            'type' => 'auction', // Default for these tests
        ];
    }
}
