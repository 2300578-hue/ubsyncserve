<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->timestamps();
        });

        Schema::create('ingredient_product', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['ingredient_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_product');
        Schema::dropIfExists('ingredients');
    }
};
