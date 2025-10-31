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
            $table->timestamps();
            $table->foreignId("barangay_id");
            $table->dateTime("reported_at");
            $table->string("risk_level");
            $table->float("accumulated_rainfall")->default(0);
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
