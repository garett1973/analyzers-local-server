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
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('c_order_id');
            $table->dropColumn('is_additional');
            $table->dropColumn('tests_count');
            $table->dropColumn('completed_tests_count');
            $table->dropColumn('is_completed');
            $table->dropColumn('processing_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('c_order_id')->nullable();
            $table->boolean('is_additional')->nullable();
            $table->integer('tests_count')->nullable();
            $table->integer('completed_tests_count')->nullable();
            $table->boolean('is_completed')->nullable();
            $table->string('processing_status')->nullable();
        });
    }
};
