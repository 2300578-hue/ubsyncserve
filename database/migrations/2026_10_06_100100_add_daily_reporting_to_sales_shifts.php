<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_shifts', function (Blueprint $table): void {
            $table->foreignId('ended_by')->nullable()->after('started_by')->constrained('users')->nullOnDelete();
            $table->decimal('opening_float', 12, 2)->default(0)->after('started_at');
            $table->decimal('gross_sales', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('net_sales', 12, 2)->default(0);
            $table->decimal('vat_total', 12, 2)->default(0);
            $table->decimal('cash_sales', 12, 2)->default(0);
            $table->decimal('gcash_sales', 12, 2)->default(0);
            $table->json('z_read')->nullable();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('subtotal_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->timestamp('paid_at')->nullable()->index();
        });

        Schema::create('sales_settings', function (Blueprint $table): void {
            $table->id();
            $table->time('business_day_cutoff')->default('00:00:00');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_settings');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'subtotal_amount',
                'discount_amount',
                'vat_amount',
                'payment_method',
                'paid_at',
            ]);
        });

        Schema::table('sales_shifts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ended_by');
            $table->dropColumn([
                'opening_float',
                'gross_sales',
                'discount_total',
                'net_sales',
                'vat_total',
                'cash_sales',
                'gcash_sales',
                'z_read',
            ]);
        });
    }
};
