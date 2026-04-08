<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_likes', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('product_id')->constrained()->onDelete('cascade');
            $blueprint->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $blueprint->string('ip_address')->nullable();
            $blueprint->timestamps();

            // Unique like per user or per product-ip (for guests)
            $blueprint->unique(['product_id', 'user_id']);
            // We can't easily unique-ify product_id + ip_address where user_id is null in a DB index, 
            // but we can handle it in the controller or use a partial index if DB supports it.
            // For simplicity, we'll index them for faster lookups.
            $blueprint->index(['product_id', 'ip_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_likes');
    }
};
