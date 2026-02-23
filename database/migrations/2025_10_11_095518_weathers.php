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
        Schema::create('weathers', function (Blueprint $table) {
            $table->id();
            $table->foreignId("barangay_id");
            $table->json("data")->nullable();
            $table->double('solar_irradiance')->default(0.0);
            $table->double('temp_min')->default(0.0);
            $table->double('temp_max')->default(0.0);
            $table->double('temp_avg')->default(0.0);
            $table->double('hargreaves_index')->default(0.0);
            $table->double('hargreaves_hourly')->default(0.0);
            $table->double("accumulated_rainfall")->default(0);
            $table->double('soil_moisture')->default(0.0);
            $table->double('si_score')->default(0.0);
            $table->double('runoff')->default(0.0);
            $table->double('ave_pop_percentage')->default(0.0)->after('accumulated_rainfall')->nullable();
            $table->dateTime("fetched_at");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weathers');

    }
};
