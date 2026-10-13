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
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('continue_on_robots_error')->default(false);
        });
        Schema::table('pages', function (Blueprint $table) {
            $table->text('warning')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('continue_on_robots_error');
        });
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('warning');
        });
    }
};
