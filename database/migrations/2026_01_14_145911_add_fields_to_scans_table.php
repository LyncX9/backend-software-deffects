<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->string('file_path')->nullable();
            $table->float('defect_probability')->default(0);
            $table->string('risk_level')->default('low');
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'defect_probability', 'risk_level']);
        });
    }
};
