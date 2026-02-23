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
        Schema::create('floodeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId("barangay_id");
            $table->integer("risk_level");
            $table->double('rwr_score');
            $table->float("accumulated_rainfall")->default(0);
            $table->json('flooded_polygon')->nullable();
            $table->dateTime("reported_at");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('floodeds');
    }
};
