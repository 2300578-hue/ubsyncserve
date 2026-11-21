<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table): void {
            $table->dropUnique('restaurant_tables_table_number_unique');
        });

        Schema::table('restaurant_tables', function (Blueprint $table): void {
            $table->unsignedBigInteger('table_number')->change();
            $table->boolean('is_active')->default(true)->after('table_number');
            $table->unique('table_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table): void {
            $table->dropColumn('is_active');
            $table->dropUnique('restaurant_tables_table_number_unique');
        });

        Schema::table('restaurant_tables', function (Blueprint $table): void {
            $table->unsignedTinyInteger('table_number')->change();
            $table->unique('table_number');
        });
    }
};
