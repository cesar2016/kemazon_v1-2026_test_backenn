<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_visits', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('product_id')->constrained()->onDelete('cascade');
            $blueprint->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $blueprint->string('ip_address')->nullable();
            $blueprint->string('session_id')->nullable(); // Unique for the current page session
            $blueprint->integer('duration')->default(0); // in seconds
            $blueprint->boolean('is_valid')->default(false); // true if duration >= 300
            $blueprint->timestamp('last_active_at')->useCurrent();
            $blueprint->timestamps();

            $blueprint->index(['product_id', 'is_valid']);
            $blueprint->index(['product_id', 'user_id', 'created_at']);
            $blueprint->index(['product_id', 'ip_address', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_visits');
    }
};
