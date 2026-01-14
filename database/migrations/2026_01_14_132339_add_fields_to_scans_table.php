<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->string('file_path')->nullable()->after('filename');
            $table->decimal('defect_probability', 5, 2)->nullable()->after('result');
            $table->string('risk_level')->nullable()->after('defect_probability');
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'defect_probability', 'risk_level']);
        });
    }
};
