<?php

namespace Database\Seeders;

use App\Models\Auction;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::create([
            'name' => 'Administrador',
            'email' => 'admin@kemazon.ar',
            'password' => Hash::make('password'),
            'is_admin' => true,
            'is_seller' => true,
            'phone' => '+54 11 0000 0000',
        ]);

        $seller = User::create([
            'name' => 'Vendedor Demo',
            'email' => 'vendedor@kemazon.ar',
            'password' => Hash::make('password'),
            'is_admin' => false,
            'is_seller' => true,
            'phone' => '+54 11 1111 1111',
        ]);

        User::create([
            'name' => 'Comprador Demo',
            'email' => 'comprador@kemazon.ar',
            'password' => Hash::make('password'),
            'is_admin' => false,
            'is_seller' => false,
        ]);

        $electronics = Category::create([
            'name' => 'Electrónica',
            'slug' => 'electronica',
            'parent_id' => null,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $vehicles = Category::create([
            'name' => 'Vehículos',
            'slug' => 'vehiculos',
            'parent_id' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $products = [
            [
                'name' => 'iPhone 15 Pro Max 256GB',
                'slug' => 'iphone-15-pro-max-256gb',
                'description' => 'iPhone 15 Pro Max en perfecto estado, con garantía de Apple.',
                'price' => 1500000,
                'category_id' => $electronics->id,
                'user_id' => $seller->id,
                'thumbnail' => 'https://images.unsplash.com/photo-1695048133142-1a20484d2569?w=400',
                'stock' => 1,
            ],
            [
                'name' => 'MacBook Pro 14" M3 Pro',
                'slug' => 'macbook-pro-14-m3-pro',
                'description' => 'MacBook Pro 14 pulgadas con chip M3 Pro, 18GB RAM, 512GB SSD.',
                'price' => 2800000,
                'category_id' => $electronics->id,
                'user_id' => $seller->id,
                'thumbnail' => 'https://images.unsplash.com/photo-1517336714731-489689fd1ca8?w=400',
                'stock' => 1,
            ],
            [
                'name' => 'PlayStation 5 Digital Edition',
                'slug' => 'playstation-5-digital',
                'description' => 'PS5 Digital Edition nueva, sin disco, lista para jugar.',
                'price' => 950000,
                'category_id' => $electronics->id,
                'user_id' => $seller->id,
                'thumbnail' => 'https://images.unsplash.com/photo-1606144042614-b2417e99c4e3?w=400',
                'stock' => 1,
            ],
            [
                'name' => 'Volkswagen Golf GTI 2023',
                'slug' => 'volkswagen-golf-gti-2023',
                'description' => 'VW Golf GTI automático, 0km, full equipo.',
                'price' => 45000000,
                'category_id' => $vehicles->id,
                'user_id' => $seller->id,
                'thumbnail' => 'https://images.unsplash.com/photo-1606611013016-969c19ba27bb?w=400',
                'stock' => 1,
            ],
            [
                'name' => 'Samsung Galaxy S24 Ultra',
                'slug' => 'samsung-galaxy-s24-ultra',
                'description' => 'Samsung Galaxy S24 Ultra 512GB,钛灰色, nuevo.',
                'price' => 1800000,
                'category_id' => $electronics->id,
                'user_id' => $seller->id,
                'thumbnail' => 'https://images.unsplash.com/photo-1610945415295-d9bbf067e59c?w=400',
                'stock' => 1,
            ],
            [
                'name' => 'DJI Mini 4 Pro Dron',
                'slug' => 'dji-mini-4-pro',
                'description' => 'Dron DJI Mini 4 Pro con control remoto, como nuevo.',
                'price' => 850000,
                'category_id' => $electronics->id,
                'user_id' => $seller->id,
                'thumbnail' => 'https://images.unsplash.com/photo-1473968512647-3e447244af8f?w=400',
                'stock' => 1,
            ],
        ];

        foreach ($products as $productData) {
            $product = Product::create($productData);

            $basePrice = $productData['price'];
            $startingPrice = $basePrice * 0.7;
            $buyNowPrice = $basePrice * 0.95;

            Auction::create([
                'product_id' => $product->id,
                'starting_price' => $startingPrice,
                'current_price' => $startingPrice,
                'reserve_price' => $basePrice * 0.85,
                'buy_now_price' => $buyNowPrice,
                'starts_at' => now()->subHours(2),
                'ends_at' => now()->addHours(rand(6, 72)),
                'is_active' => true,
                'has_reserve' => true,
                'status' => 'active',
            ]);
        }

        $categories = [
            ['name' => 'Electrónica', 'slug' => 'electronica', 'icon' => 'cpu', 'children' => [
                ['name' => 'Smartphones', 'slug' => 'smartphones'],
                ['name' => 'Computadoras', 'slug' => 'computadoras'],
                ['name' => 'Tablets', 'slug' => 'tablets'],
                ['name' => 'Accesorios', 'slug' => 'accesorios-electronica'],
            ]],
            ['name' => 'Vehículos', 'slug' => 'vehiculos', 'icon' => 'car', 'children' => [
                ['name' => 'Autos', 'slug' => 'autos'],
                ['name' => 'Motos', 'slug' => 'motos'],
                ['name' => 'Repuestos', 'slug' => 'repuestos'],
            ]],
            ['name' => 'Inmuebles', 'slug' => 'inmuebles', 'icon' => 'home', 'children' => [
                ['name' => 'Departamentos', 'slug' => 'departamentos'],
                ['name' => 'Casas', 'slug' => 'casas'],
                ['name' => 'Terrenos', 'slug' => 'terrenos'],
            ]],
            ['name' => 'Moda', 'slug' => 'moda', 'icon' => 'shirt', 'children' => [
                ['name' => 'Ropa Hombre', 'slug' => 'ropa-hombre'],
                ['name' => 'Ropa Mujer', 'slug' => 'ropa-mujer'],
                ['name' => 'Calzado', 'slug' => 'calzado'],
            ]],
            ['name' => 'Hogar', 'slug' => 'hogar', 'icon' => 'sofa', 'children' => [
                ['name' => 'Muebles', 'slug' => 'muebles'],
                ['name' => 'Electrodomésticos', 'slug' => 'electrodomesticos'],
                ['name' => 'Decoración', 'slug' => 'decoracion'],
            ]],
            ['name' => 'Deportes', 'slug' => 'deportes', 'icon' => 'dumbbell', 'children' => [
                ['name' => 'Fitness', 'slug' => 'fitness'],
                ['name' => 'Fútbol', 'slug' => 'futbol'],
                ['name' => 'Cycling', 'slug' => 'cycling'],
            ]],
            ['name' => 'Arte y Colecciones', 'slug' => 'arte', 'icon' => 'palette', 'children' => [
                ['name' => 'Pinturas', 'slug' => 'pinturas'],
                ['name' => 'Esculturas', 'slug' => 'esculturas'],
                ['name' => 'Colecciones', 'slug' => 'colecciones'],
            ]],
            ['name' => 'Otros', 'slug' => 'otros', 'icon' => 'box', 'children' => []],
        ];

        $this->createCategories($categories);
    }

    private function createCategories(array $categories, ?int $parentId = null): void
    {
        foreach ($categories as $index => $categoryData) {
            $category = Category::create([
                'name' => $categoryData['name'],
                'slug' => $categoryData['slug'],
                'parent_id' => $parentId,
                'is_active' => true,
                'sort_order' => $index,
            ]);

            if (!empty($categoryData['children'])) {
                $this->createCategories($categoryData['children'], $category->id);
            }
        }
    }
}
