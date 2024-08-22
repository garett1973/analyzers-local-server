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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('c_order_id');
            $table->string('external_id')->nullable();
            $table->string('order_barcode');
            $table->string('test_barcode')->nullable();
            $table->string('order_record');
            $table->boolean('is_additional')->default(false);
            $table->integer('tests_count')->default(0);
            $table->integer('completed_tests_count')->default(0);
            $table->boolean('is_completed')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
