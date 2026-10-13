<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $table->date('business_date')->index();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('status')->default('open')->index();
            $table->unsignedInteger('order_count')->default(0);
            $table->unsignedInteger('items_sold')->default(0);
            $table->decimal('total_sales', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('sales_shift_id')->nullable()->constrained('sales_shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sales_shift_id');
        });

        Schema::dropIfExists('sales_shifts');
    }
};
