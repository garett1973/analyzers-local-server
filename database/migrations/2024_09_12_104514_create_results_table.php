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
        Schema::create('results', function (Blueprint $table) {
            $table->id()->index();
            $table->string('order_id')->index()->nullable();
            $table->string('barcode')->index();
            $table->string('analyte_code')->index()->nullable();
            $table->string('lis_code')->index()->nullable();
            $table->longText('result')->nullable();
            $table->string('unit')->nullable();
            $table->string('reference_range')->nullable();
            $table->longText('original_string')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('results');
    }
};
