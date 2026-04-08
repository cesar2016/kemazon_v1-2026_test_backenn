<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('seller_id')->nullable()->after('user_id')->constrained('users')->onDelete('set null');
            $table->string('mercadopago_preference_id')->nullable()->after('payment_id');
            $table->text('payment_url')->nullable()->after('mercadopago_preference_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['seller_id']);
            $table->dropColumn(['seller_id', 'mercadopago_preference_id', 'payment_url']);
        });
    }
};
