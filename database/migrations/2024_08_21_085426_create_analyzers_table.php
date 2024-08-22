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
        Schema::create('analyzers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('analyzer_id')->index();
            $table->string('lab_id')->index();
            $table->string('name')->nullable();
            $table->integer('type_id')->index();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('location')->nullable();
            $table->string('local_ip')->nullable();
            $table->boolean('is_oneway')->default(false);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analyzers');
    }
};
