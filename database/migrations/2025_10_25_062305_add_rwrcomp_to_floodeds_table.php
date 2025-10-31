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
        Schema::table('floodeds', function (Blueprint $table) {
            $table->json('flooded_polygon')->nullable()->after('accumulated_rainfall');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('floodeds', function (Blueprint $table) {
            $table->dropColumn('flooded_polygon');
        });
    }
};
