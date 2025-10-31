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
        Schema::table('weathers', function (Blueprint $table) {
            $table->double('ave_pop_percentage')->default(0.0)->after('accumulated_rainfall');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('weathers', function (Blueprint $table) {
            $table->dropColumn('ave_pop_percentage');
        });
    }
};
