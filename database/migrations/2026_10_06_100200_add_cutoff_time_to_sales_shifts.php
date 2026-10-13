<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_shifts', function (Blueprint $table): void {
            $table->time('cutoff_time')->nullable()->after('business_date');
        });
    }

    public function down(): void
    {
        Schema::table('sales_shifts', function (Blueprint $table): void {
            $table->dropColumn('cutoff_time');
        });
    }
};
