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
        Schema::create('crucial_facilities', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->double('latitude')->nullable();
            $table->double('longitude')->nullable();

            $table->string('barangay')->nullable();
            $table->string('municipality');

            $table->string('postal_code', 20)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('type');

            $table->timestamps();

            $table->unique(['name', 'barangay', 'municipality', 'type']);
            $table->index(['municipality', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crucial_facilities');
    }
};
